<?php

namespace Drupal\court_booking;

use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_bat\Util\DateTimeHelper;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared JSON logic for session-based and OAuth REST court booking endpoints.
 */
class CourtBookingApiService {

  use StringTranslationTrait;

  public function __construct(
    protected BatUnitEnsurer $batUnitEnsurer,
    protected CartManagerInterface $cartManager,
    protected CartProviderInterface $cartProvider,
    protected CurrentStoreInterface $currentStore,
    protected LoggerInterface $logger,
    protected CourtBookingSlotBooking $slotBooking,
    protected OrderRefreshInterface $orderRefresh,
    protected AvailabilityManagerInterface $availabilityManager,
    protected ConfigFactoryInterface $configFactory,
    protected CheckoutOrderManagerInterface $checkoutOrderManager,
    protected CourtBookingSportSettings $sportSettings,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected CourtBookingPriceResolver $priceResolver,
    protected CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * Slot candidates (buffer mode) from decoded JSON body.
   *
   * @param array $data
   *   Same keys as SlotCandidatesController.
   *
   * @return array{status: int, data: array}
   */
  public function buildSlotCandidatesResponse(array $data, AccountInterface $account): array {
    $ymd = trim((string) ($data['ymd'] ?? ''));
    $play_minutes = (int) ($data['duration_minutes'] ?? 0);
    if ($play_minutes <= 0) {
      $play_minutes = max(1, min(24, (int) ($data['duration_hours'] ?? 1))) * 60;
    }
    $play_minutes = max(1, min(24 * 60, $play_minutes));
    $variation_raw = $data['variation_ids'] ?? [];
    if (!is_array($variation_raw)) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('variation_ids must be an array.')]];
    }
    $variation_ids = array_values(array_unique(array_filter(array_map('intval', $variation_raw))));
    $quantity = max(1, (int) ($data['quantity'] ?? 1));

    if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid date.')]];
    }
    if (!$variation_ids) {
      return ['status' => 200, 'data' => ['slots' => []]];
    }

    $variations = [];
    foreach ($variation_ids as $vid) {
      $v = ProductVariation::load($vid);
      if ($v) {
        $variations[$vid] = $v;
      }
    }
    if (!$variations) {
      return ['status' => 200, 'data' => ['slots' => []]];
    }

    $filtered_ids = [];
    foreach ($variation_ids as $vid) {
      if (!isset($variations[$vid])) {
        continue;
      }
      $v = $variations[$vid];
      if (\court_booking_variation_is_configured($v) && \court_booking_variation_has_published_court_node($v)) {
        $filtered_ids[] = $vid;
      }
    }

    if ($filtered_ids) {
      $slot_lens = [];
      foreach ($filtered_ids as $vid) {
        $v = $variations[$vid];
        $slot_lens[] = max(1, (int) $this->availabilityManager->getLessonSlotLength($v));
      }
      if (!CourtBookingPlayDurationGrid::playMinutesValidForSlots($play_minutes, $slot_lens)) {
        return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid play duration for these courts.')]];
      }
    }

    $slots = [];
    if ($filtered_ids) {
      $first_vid = (int) reset($filtered_ids);
      $first_variation = $variations[$first_vid] ?? NULL;
      $rules = $first_variation instanceof ProductVariationInterface
        ? $this->sportSettings->getMergedForVariation($first_variation)
        : $this->sportSettings->getGlobalBookingRules();
      $buffer_minutes = max(0, min(180, (int) ($rules['buffer_minutes'] ?? 0)));
      $slots = $buffer_minutes > 0
        ? $this->slotBooking->buildBufferSlotCandidatesForDay($filtered_ids, $ymd, $play_minutes, $quantity, $account)
        : $this->slotBooking->buildNonBufferSlotCandidatesForDay($filtered_ids, $ymd, $play_minutes, $quantity, $account);
    }
    foreach ($slots as &$slot) {
      if (!is_array($slot)) {
        continue;
      }
      if (isset($slot['start']) && is_string($slot['start'])) {
        $slot['start'] = $this->formatUtcStringToSiteIso($slot['start']) ?? $slot['start'];
      }
      if (isset($slot['end']) && is_string($slot['end'])) {
        $slot['end'] = $this->formatUtcStringToSiteIso($slot['end']) ?? $slot['end'];
      }
    }
    unset($slot);

    return ['status' => 200, 'data' => ['slots' => $slots]];
  }

  /**
   * Validates a slot and returns server-computed play price (no cart writes).
   *
   * @param array<string, mixed> $data
   *   Keys: variation_id, start, end, quantity (optional).
   *
   * @return array{status: int, data: array}
   */
  public function buildPricePreviewResponse(array $data, AccountInterface $account): array {
    $variation_id = (int) ($data['variation_id'] ?? 0);
    $start_raw = $data['start'] ?? '';
    $end_raw = $data['end'] ?? '';
    $quantity = max(1, (int) ($data['quantity'] ?? 1));

    if (!$variation_id || $start_raw === '' || $end_raw === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing variation or time range.')]];
    }

    $variation = ProductVariation::load($variation_id);
    if (!$variation) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid product variation.')]];
    }

    if (!\court_booking_variation_is_configured($variation) || !\court_booking_variation_has_published_court_node($variation)) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('This court is not available for booking.')]];
    }

    $validation = $this->slotBooking->validateLessonSlot(
      $variation,
      (string) $start_raw,
      (string) $end_raw,
      $quantity,
      $account,
    );
    if (!$validation['ok']) {
      return ['status' => $validation['status'], 'data' => ['message' => $validation['message']]];
    }

    /** @var \DateTimeImmutable $start */
    $start = $validation['start'];
    $billing_units = (int) $validation['billing_units'];
    $base = $variation->getPrice();
    $per_unit = $this->priceResolver->resolvePerBillingUnitPrice($variation, $start, $account);
    $total = $per_unit && $billing_units >= 1
      ? $per_unit->multiply((string) $billing_units)
      : NULL;

    $surcharge_per_unit = NULL;
    if ($per_unit instanceof Price && $base instanceof Price) {
      try {
        $surcharge_per_unit = $per_unit->subtract($base);
      }
      catch (\Throwable) {
        $surcharge_per_unit = NULL;
      }
    }

    $out = [
      'variation_id' => $variation_id,
      'billing_units' => $billing_units,
      'currency_code' => $total ? $total->getCurrencyCode() : ($base ? $base->getCurrencyCode() : ''),
      'total_number' => $total ? $total->getNumber() : '',
      'total_formatted' => $total
        ? $this->currencyFormatter->format($total->getNumber(), $total->getCurrencyCode())
        : '',
      'per_billing_unit_number' => $per_unit ? $per_unit->getNumber() : '',
      'per_billing_unit_formatted' => $per_unit
        ? $this->currencyFormatter->format($per_unit->getNumber(), $per_unit->getCurrencyCode())
        : '',
      'base_per_unit_number' => $base ? $base->getNumber() : '',
      'surcharge_per_unit_number' => $surcharge_per_unit ? $surcharge_per_unit->getNumber() : '0',
    ];

    return ['status' => 200, 'data' => $out];
  }

  /**
   * Single-range availability check using the same validator as booking flows.
   *
   * @return array{status: int, data: array}
   */
  public function buildSlotCheckResponse(ProductVariationInterface $variation, Request $request, AccountInterface $account): array {
    $start_raw = trim((string) $request->query->get('start', ''));
    $end_raw = trim((string) $request->query->get('end', ''));
    $quantity = max(1, (int) $request->query->get('quantity', 1));

    if ($start_raw === '' || $end_raw === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing required query params: start, end')]];
    }

    $start_utc = $this->normalizeApiDateTimeInputToUtc($start_raw);
    $end_utc = $this->normalizeApiDateTimeInputToUtc($end_raw);
    if ($start_utc === NULL || $end_utc === NULL) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid date format.')]];
    }

    $validation = $this->slotBooking->validateLessonSlot(
      $variation,
      $start_utc,
      $end_utc,
      $quantity,
      $account,
      FALSE,
    );

    $available = !empty($validation['ok']);
    $data = [
      'available' => $available,
      'quantity' => $quantity,
      'timezone' => $this->siteTimezoneId(),
      'start' => $this->formatUtcStringToSiteIso($start_utc) ?? $start_raw,
      'end' => $this->formatUtcStringToSiteIso($end_utc) ?? $end_raw,
    ];
    if (!$available) {
      $data['message'] = (string) ($validation['message'] ?? $this->t('That slot is not available.'));
      $data['reason_status'] = (int) ($validation['status'] ?? 409);
    }

    return ['status' => 200, 'data' => $data];
  }

  /**
   * Builds rule-aware availability slots for a variation and date range.
   *
   * @return array{status: int, data: array}
   */
  public function buildAvailabilityResponse(ProductVariationInterface $variation, Request $request, AccountInterface $account): array {
    $from_raw = trim((string) $request->query->get('from', ''));
    $to_raw = trim((string) $request->query->get('to', ''));
    if ($from_raw === '' || $to_raw === '') {
      return ['status' => 400, 'data' => ['error' => 'Missing required query params: from, to']];
    }

    $interval_raw = trim((string) $request->query->get('interval', ''));
    $slot_minutes = max(1, (int) $this->availabilityManager->getLessonSlotLength($variation));
    $interval_minutes = $this->normalizeIntervalMinutes($interval_raw, $slot_minutes);
    if ($interval_minutes === NULL) {
      return ['status' => 400, 'data' => ['error' => 'Invalid interval']];
    }

    // Pull booking rules from merged variation-level configuration.
    $merged_rules = $this->sportSettings->getMergedForVariation($variation);
    $buffer_minutes = max(0, min(180, (int) ($merged_rules['buffer_minutes'] ?? 0)));
    $block_minutes = $interval_minutes + $buffer_minutes;

    $site_tz = $this->siteTimezoneId();
    try {
      $tz = new \DateTimeZone($site_tz);
    }
    catch (\Throwable $e) {
      $site_tz = 'UTC';
      $tz = new \DateTimeZone($site_tz);
    }

    $from_local = $this->parseLocalDate($from_raw, $tz);
    $to_local = $this->parseLocalDate($to_raw, $tz);
    if (!$from_local || !$to_local) {
      return ['status' => 400, 'data' => ['error' => 'Invalid from/to date']];
    }
    if ($to_local < $from_local) {
      return ['status' => 400, 'data' => ['error' => 'Invalid range: to must be >= from']];
    }

    $open_m = $this->slotBooking->parseHmToMinutes((string) ($merged_rules['booking_day_start'] ?? '06:00'));
    $close_m = $this->slotBooking->parseHmToMinutes((string) ($merged_rules['booking_day_end'] ?? '23:00'));
    if ($open_m === NULL || $close_m === NULL || $close_m <= $open_m) {
      $open_m = 0;
      $close_m = 24 * 60;
    }
    $step_minutes = $buffer_minutes > 0 ? $block_minutes : $interval_minutes;

    $slots = [];
    $cursor = $from_local;
    while ($cursor <= $to_local) {
      $ymd = $cursor->format('Y-m-d');
      for ($start_m = $open_m; $start_m + $block_minutes <= $close_m; $start_m += $step_minutes) {
        $local_start = $cursor->setTime(intdiv($start_m, 60), $start_m % 60, 0);
        $start_utc = DateTimeHelper::normalizeUtc($local_start->setTimezone(new \DateTimeZone('UTC')));
        $end_utc = $start_utc->add(new \DateInterval('PT' . $block_minutes . 'M'));
        $validation = $this->slotBooking->validateLessonSlot(
          $variation,
          DateTimeHelper::formatUtc($start_utc),
          DateTimeHelper::formatUtc($end_utc),
          1,
          $account,
          FALSE,
        );
        if (!empty($validation['ok'])) {
          $slots[] = [
            'start' => $this->formatUtcToSiteIso($start_utc),
            'end' => $this->formatUtcToSiteIso($end_utc),
            'ymd' => $ymd,
          ];
        }
      }
      $cursor = $cursor->modify('+1 day');
    }

    return [
      'status' => 200,
      'data' => [
        'variation_id' => (int) $variation->id(),
        'timezone' => $site_tz,
        'interval' => 'PT' . $interval_minutes . 'M',
        'interval_minutes' => $interval_minutes,
        'buffer_minutes' => $buffer_minutes,
        'from' => $from_local->format('Y-m-d'),
        'to' => $to_local->format('Y-m-d'),
        'slots' => $slots,
      ],
    ];
  }

  /**
   * Returns mobile bootstrap data equivalent to page drupalSettings payload.
   *
   * @return array{status: int, data: array}
   */
  public function buildSportsBootstrapResponse(): array {
    $config = $this->configFactory->get('court_booking.settings');
    $commerce_bat = $this->configFactory->get('commerce_bat.settings');
    $mappings = $config->get('sport_mappings') ?: [];
    if (!is_array($mappings)) {
      return ['status' => 200, 'data' => ['sports' => []]];
    }

    $entity_type_manager = \Drupal::entityTypeManager();
    $term_storage = $entity_type_manager->getStorage('taxonomy_term');
    $variation_storage = $entity_type_manager->getStorage('commerce_product_variation');
    $product_storage = $entity_type_manager->getStorage('commerce_product');
    $file_url_generator = \Drupal::service('file_url_generator');
    $language_manager = \Drupal::languageManager();

    $slot_minutes = max(1, (int) ($commerce_bat->get('lesson_slot_length_minutes') ?: 60));
    $site_tz = $this->siteTimezoneId();
    $langcode = $language_manager->getCurrentLanguage()->getId();

    $sports = [];
    foreach ($mappings as $row) {
      $tid = (int) ($row['sport_tid'] ?? 0);
      if ($tid <= 0) {
        continue;
      }
      $variation_entities = $this->mappedSportVariations($row, $variation_storage, $product_storage);
      if ($variation_entities === []) {
        continue;
      }

      $term = $term_storage->load($tid);
      $label = $term ? $term->label() : (string) $tid;
      $sport_slug = \court_booking_sport_slugify($label);

      $variations_out = [];
      foreach ($variation_entities as $variation) {
        $court_node = \court_booking_variation_published_court_node($variation);
        if (!$court_node instanceof NodeInterface) {
          continue;
        }
        $price = '';
        $price_amount = '';
        $price_currency = '';
        $variation_price = $variation->getPrice();
        if ($variation_price) {
          $price = $this->currencyFormatter->format($variation_price->getNumber(), $variation_price->getCurrencyCode());
          $price_amount = $variation_price->getNumber();
          $price_currency = $variation_price->getCurrencyCode();
        }
        $card = CourtBookingVariationThumbnail::data($variation, $file_url_generator);
        $slot_len = max(1, (int) $this->availabilityManager->getLessonSlotLength($variation));
        $variations_out[] = array_merge([
          'id' => (int) $variation->id(),
          'title' => $court_node->getTitle(),
          'courtTitle' => $court_node->getTitle(),
          'variationTitle' => $variation->getTitle(),
          'price' => $price,
          'priceAmount' => $price_amount,
          'priceCurrencyCode' => $price_currency,
          'image' => $card['url'] ?? '',
          'slotMinutes' => $slot_len,
          'detailUrl' => Url::fromRoute('court_booking.court_detail', [
            'commerce_product_variation' => $variation->id(),
          ])->setAbsolute()->toString(),
        ], $this->priceResolver->variationPricingBootstrap($variation));
      }
      if ($variations_out === []) {
        continue;
      }

      $merged = $this->sportSettings->getMergedForSport($tid);
      $slot_lens = array_map(static fn(array $item): int => max(1, (int) ($item['slotMinutes'] ?? 60)), $variations_out);
      $sports[] = [
        'id' => (string) $tid,
        'label' => $label,
        'slug' => $sport_slug,
        'url' => Url::fromRoute('court_booking.booking_page', ['sport' => $sport_slug])->toString(),
        'variations' => $variations_out,
        'booking' => $this->sportSettings->bookingRulesForJs($merged, $site_tz, $langcode),
        'durationGridMinutes' => CourtBookingPlayDurationGrid::lcmMany($slot_lens),
      ];
    }

    $default_tid = $sports !== [] ? (string) ($sports[0]['id'] ?? '') : '';
    $root_rules = $default_tid !== '' ? $this->sportSettings->getMergedForSport((int) $default_tid) : $this->sportSettings->getGlobalBookingRules();
    $root_booking = $this->sportSettings->bookingRulesForJs($root_rules, $site_tz, $langcode);

    return [
      'status' => 200,
      'data' => [
        'sports' => $sports,
        'initialSportId' => $default_tid,
        'slotInterval' => 'PT' . $slot_minutes . 'M',
        'slotMinutes' => $slot_minutes,
        'timezone' => $site_tz,
        'interfaceLangcode' => $langcode,
        'intlLocale' => CourtBookingRegional::intlLocaleForLangcode($langcode),
        'countryCode' => CourtBookingRegional::defaultCountryCode($this->configFactory),
        'firstDayOfWeek' => CourtBookingRegional::firstDayOfWeek($this->configFactory),
        'bookingDayStart' => (string) ($root_booking['bookingDayStart'] ?? '06:00'),
        'bookingDayEnd' => (string) ($root_booking['bookingDayEnd'] ?? '23:00'),
        'bufferMinutes' => (int) ($root_booking['bufferMinutes'] ?? 0),
        'sameDayCutoffHm' => (string) ($root_booking['sameDayCutoffHm'] ?? ''),
        'blackoutDates' => (array) ($root_booking['blackoutDates'] ?? []),
        'resourceClosuresByVariation' => (array) ($root_booking['resourceClosuresByVariation'] ?? []),
        'dates' => (array) ($root_booking['dates'] ?? []),
        'maxBookingHours' => (int) ($root_booking['maxBookingHours'] ?? 4),
        'pricePreviewUrl' => Url::fromRoute('court_booking.price_preview')->setAbsolute()->toString(),
      ],
    ];
  }

  /**
   * Parses interval query into minutes.
   */
  private function normalizeIntervalMinutes(string $interval_raw, int $default_minutes): ?int {
    $raw = trim($interval_raw);
    if ($raw === '') {
      return $default_minutes;
    }
    if (preg_match('/^\d+$/', $raw)) {
      $minutes = (int) $raw;
      return $minutes > 0 ? $minutes : NULL;
    }
    if (preg_match('/^PT(\d+)M$/i', $raw, $m)) {
      $minutes = (int) ($m[1] ?? 0);
      return $minutes > 0 ? $minutes : NULL;
    }
    return NULL;
  }

  /**
   * Parses incoming date-like query input into local day in target timezone.
   */
  private function parseLocalDate(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable {
    $value = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return new \DateTimeImmutable($value . ' 00:00:00', $tz);
    }
    try {
      $dt = new \DateTimeImmutable($value);
      return new \DateTimeImmutable($dt->setTimezone($tz)->format('Y-m-d') . ' 00:00:00', $tz);
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * Loads published variations mapped for a sport mapping row.
   *
   * @return array<int, ProductVariationInterface>
   */
  private function mappedSportVariations(array $row, $variation_storage, $product_storage): array {
    $product_id = (int) ($row['product_id'] ?? 0);
    $legacy_vids = array_map('intval', (array) ($row['variation_ids'] ?? []));
    $variation_entities = [];
    if ($product_id > 0) {
      $product = $product_storage->load($product_id);
      if ($product instanceof ProductInterface) {
        foreach ($product->getVariations() as $variation) {
          if ($variation instanceof ProductVariationInterface && $variation->isPublished()) {
            $variation_entities[] = $variation;
          }
        }
      }
    }
    else {
      foreach ($legacy_vids as $vid) {
        $variation = $variation_storage->load($vid);
        if ($variation instanceof ProductVariationInterface && $variation->isPublished()) {
          $variation_entities[] = $variation;
        }
      }
    }
    return $variation_entities;
  }

  /**
   * Validates and adds a court line to the cart.
   *
   * @param array $data
   *   Keys: variation_id, start, end, quantity.
   * @param array $options
   *   - include_legacy_redirect: bool (default TRUE for web JSON).
   *   - include_rest_fields: bool (default FALSE); adds order_id, order_item_id, totals.
   *
   * @return array{status: int, data: array}
   */
  public function addBookingLineItem(AccountInterface $account, array $data, array $options = []): array {
    $include_legacy_redirect = $options['include_legacy_redirect'] ?? TRUE;
    $include_rest_fields = $options['include_rest_fields'] ?? FALSE;

    $variation_id = (int) ($data['variation_id'] ?? 0);
    $start_raw = $data['start'] ?? '';
    $end_raw = $data['end'] ?? '';
    $quantity = max(1, (int) ($data['quantity'] ?? 1));
    $start_utc_probe = $this->normalizeApiDateTimeInputToUtc((string) $start_raw);
    $end_utc_probe = $this->normalizeApiDateTimeInputToUtc((string) $end_raw);

    if (!$variation_id || $start_raw === '' || $end_raw === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing variation or time range.')]];
    }

    $variation = ProductVariation::load($variation_id);
    if (!$variation) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid product variation.')]];
    }

    $isConfigured = \court_booking_variation_is_configured($variation);
    $hasCourtNode = \court_booking_variation_has_published_court_node($variation);
    if (!$isConfigured) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('This court is not enabled for the booking page.')]];
    }

    if (!$hasCourtNode) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('This court is not available for booking.')]];
    }

    $start_for_validation = $start_utc_probe ?? (string) $start_raw;
    $end_for_validation = $end_utc_probe ?? (string) $end_raw;
    if ($start_utc_probe === NULL || $end_utc_probe === NULL) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid date format.')]];
    }
    $validation = $this->slotBooking->validateLessonSlot(
      $variation,
      $start_for_validation,
      $end_for_validation,
      $quantity,
      $account,
    );
    if (!$validation['ok']) {
      return ['status' => $validation['status'], 'data' => ['message' => $validation['message']]];
    }

    /** @var \DateTimeImmutable $start */
    $start = $validation['start'];
    /** @var \DateTimeImmutable $end */
    $end = $validation['end'];
    $billing_units = (int) $validation['billing_units'];

    if (!$this->batUnitEnsurer->ensureBookableUnit($variation)) {
      $this->logger->error('No BAT unit could be resolved for variation @id.', ['@id' => $variation->id()]);
      return ['status' => 500, 'data' => [
        'message' => (string) $this->t('This court is not linked to a bookable unit yet. In Commerce BAT settings, confirm lesson unit bundle and unit type, then run database updates (drush updb).'),
      ]];
    }

    $cb_config = $this->configFactory->get('court_booking.settings');
    $order_type = $cb_config->get('order_type_id') ?: 'default';
    $store = $this->currentStore->getStore();
    if (!$store) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('No active store is configured.')]];
    }

    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if (!$cart) {
      $cart = $this->cartProvider->createCart($order_type, $store, $account);
    }

    $order_item = $this->cartManager->createOrderItem($variation, (string) $quantity);
    if (!$order_item->hasField('field_cbat_rental_date')) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Order items are missing the rental date field.')]];
    }

    $this->slotBooking->applyRentalAndPrice($order_item, $variation, $start, $end, $billing_units, $account);

    try {
      $this->cartManager->addOrderItem($cart, $order_item, FALSE, TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->error('Court booking add failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not add to cart. Please try again.')]];
    }

    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if ($cart && $include_rest_fields) {
      foreach ($cart->getItems() as $candidate) {
        if ((int) $candidate->id() === (int) $order_item->id()) {
          $order_item = $candidate;
          break;
        }
      }
    }
    $response = [
      'status' => 'ok',
    ];
    if ($include_legacy_redirect) {
      $redirect_url = Url::fromRoute('commerce_cart.page')->setAbsolute()->toString();
      $court_node = CourtBookingVariationThumbnail::courtNode($variation);
      if ($court_node && $court_node->access('view', $account)) {
        $redirect_url = $court_node->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
      $response['redirect'] = $redirect_url;
    }
    if ($include_rest_fields && $cart) {
      $this->orderRefresh->refresh($cart);
      $cart->save();
      $fresh_item_id = $order_item->id();
      $response['order_id'] = (int) $cart->id();
      $response['order_item_id'] = $fresh_item_id ? (int) $fresh_item_id : NULL;
      $response['total'] = $cart->getTotalPrice() ? $cart->getTotalPrice()->__toString() : NULL;
      $step_id = $this->checkoutOrderManager->getCheckoutStepId($cart);
      $response['checkout_url'] = Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $cart->id(),
        'step' => $step_id,
      ], ['absolute' => TRUE])->toString();
    }

    return ['status' => 200, 'data' => $response];
  }

  /**
   * Updates rental slot on a cart line item.
   *
   * @return array{status: int, data: array}
   */
  public function updateCartLineSlot(AccountInterface $account, OrderItemInterface $commerce_order_item, array $data): array {
    $start_raw = $data['start'] ?? '';
    $end_raw = $data['end'] ?? '';
    $start_utc_probe = $this->normalizeApiDateTimeInputToUtc((string) $start_raw);
    $end_utc_probe = $this->normalizeApiDateTimeInputToUtc((string) $end_raw);
    if ($start_raw === '' || $end_raw === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing start or end time.')]];
    }

    $purchased = $commerce_order_item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('This line item has no product variation.')]];
    }

    $start_for_validation = $start_utc_probe ?? (string) $start_raw;
    $end_for_validation = $end_utc_probe ?? (string) $end_raw;
    if ($start_utc_probe === NULL || $end_utc_probe === NULL) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid date format.')]];
    }
    $quantity = max(1, (int) $commerce_order_item->getQuantity());
    $validation = $this->slotBooking->validateLessonSlot(
      $purchased,
      $start_for_validation,
      $end_for_validation,
      $quantity,
      $account,
    );
    if (!$validation['ok']) {
      return ['status' => $validation['status'], 'data' => ['message' => $validation['message']]];
    }

    /** @var \DateTimeImmutable $start */
    $start = $validation['start'];
    /** @var \DateTimeImmutable $end */
    $end = $validation['end'];
    $billing_units = (int) $validation['billing_units'];

    if (!$commerce_order_item->hasField('field_cbat_rental_date')) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Order items are missing the rental date field.')]];
    }

    try {
      $this->slotBooking->applyRentalAndPrice($commerce_order_item, $purchased, $start, $end, $billing_units, $account);
      $commerce_order_item->save();
      $order = $commerce_order_item->getOrder();
      if ($order) {
        $this->orderRefresh->refresh($order);
        $order->save();
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Cart slot update failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not update booking. Please try again.')]];
    }

    $payload = ['status' => 'ok'];
    if ($order = $commerce_order_item->getOrder()) {
      $payload['order_id'] = (int) $order->id();
      $payload['order_item_id'] = (int) $commerce_order_item->id();
    }

    return ['status' => 200, 'data' => $payload];
  }

  /**
   * Cancels/removes a booking line item from user's draft cart.
   *
   * @return array{status: int, data: array}
   */
  public function cancelBookingLineItem(AccountInterface $account, OrderItemInterface $commerce_order_item): array {
    $order = $commerce_order_item->getOrder();
    if (!$order) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('Order not found for this line item.')]];
    }
    if ((int) $order->getCustomerId() !== (int) $account->id()) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('Access denied.')]];
    }
    if ($order->getState()->getId() !== 'draft') {
      return ['status' => 409, 'data' => [
        'message' => (string) $this->t('Only draft cart items can be canceled via this endpoint.'),
        'state' => $order->getState()->getId(),
      ]];
    }

    try {
      if (method_exists($order, 'removeItem')) {
        $order->removeItem($commerce_order_item);
      }
      $commerce_order_item->delete();
      $this->orderRefresh->refresh($order);
      $order->save();
      if (function_exists('commerce_bat_sync_order_events')) {
        \commerce_bat_sync_order_events($order);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Cart line item cancel failed for item @item: @msg', [
        '@item' => (int) $commerce_order_item->id(),
        '@msg' => $e->getMessage(),
      ]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not cancel booking line item.')]];
    }

    return ['status' => 200, 'data' => [
      'status' => 'ok',
      'message' => (string) $this->t('Booking line item canceled.'),
      'order_id' => (int) $order->id(),
      'order_item_id' => (int) $commerce_order_item->id(),
    ]];
  }

  /**
   * Clears all line items from the current user's court booking draft cart.
   *
   * @return array{status: int, data: array}
   */
  public function clearCart(AccountInterface $account): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    $store = $this->currentStore->getStore();
    if (!$store) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('No active store is configured.')]];
    }
    $order_type = (string) ($this->configFactory->get('court_booking.settings')->get('order_type_id') ?: 'default');
    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if (!$cart instanceof OrderInterface) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('No active court cart.')]];
    }
    if ((int) $cart->getCustomerId() !== (int) $account->id()) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('Access denied.')]];
    }

    $this->orderRefresh->refresh($cart);
    if ($cart->getState()->getId() !== 'draft') {
      return ['status' => 409, 'data' => [
        'message' => (string) $this->t('Only draft carts can be cleared.'),
        'state' => $cart->getState()->getId(),
      ]];
    }

    $removed_count = 0;
    try {
      $items = $cart->getItems();
      foreach ($items as $item) {
        if (method_exists($cart, 'removeItem')) {
          $cart->removeItem($item);
        }
        $item->delete();
        $removed_count++;
      }
      $this->orderRefresh->refresh($cart);
      $cart->save();
      if (function_exists('commerce_bat_sync_order_events')) {
        \commerce_bat_sync_order_events($cart);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('court_booking clear cart failed for order @order: @msg', [
        '@order' => (int) $cart->id(),
        '@msg' => $e->getMessage(),
      ]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not clear court cart.')]];
    }

    return ['status' => 200, 'data' => [
      'status' => 'ok',
      'message' => (string) $this->t('Cart cleared.'),
      'order_id' => (int) $cart->id(),
      'removed_count' => $removed_count,
      'remaining_items' => count($cart->getItems()),
    ]];
  }

  /**
   * Completed-order receipt for the configured court booking order type and store.
   *
   * @return array{status: int, data: array}
   */
  public function buildCourtOrderReceipt(OrderInterface $order, AccountInterface $account): array {
    if ((int) $order->getCustomerId() !== (int) $account->id()) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('Access denied.')]];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    /** @var \Drupal\commerce_order\Entity\OrderInterface|null $fresh */
    $fresh = $storage->load($order->id());
    if (!$fresh instanceof OrderInterface) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('Order not found.')]];
    }
    $this->orderRefresh->refresh($fresh);

    $cb_config = $this->configFactory->get('court_booking.settings');
    $order_type_id = (string) ($cb_config->get('order_type_id') ?: 'default');
    if ($fresh->bundle() !== $order_type_id) {
      return ['status' => 409, 'data' => [
        'message' => (string) $this->t('This order is not a court booking order.'),
        'order_type' => $fresh->bundle(),
      ]];
    }

    $store = $this->currentStore->getStore();
    if ($store && (int) $fresh->getStoreId() !== (int) $store->id()) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('Order not found.')]];
    }

    if ($fresh->getState()->getId() !== 'completed') {
      return ['status' => 409, 'data' => [
        'message' => (string) $this->t('Receipt is only available for completed orders.'),
        'state' => $fresh->getState()->getId(),
      ]];
    }

    $tz_id = $this->siteTimezoneId();
    $lines = [];
    $courts = [];

    foreach ($fresh->getItems() as $item) {
      $purchased = $item->getPurchasedEntity();
      $variation_id = $purchased instanceof ProductVariationInterface ? (int) $purchased->id() : NULL;

      $line = [
        'order_item_id' => (int) $item->id(),
        'title' => $item->getTitle(),
        'quantity' => (string) $item->getQuantity(),
        'variation_id' => $variation_id,
      ];
      $unit = $item->getUnitPrice();
      $adjusted = $item->getAdjustedTotalPrice();
      $line['unit_price'] = $unit ? $unit->__toString() : NULL;
      $line['total_price'] = $adjusted ? $adjusted->__toString() : NULL;

      $rental_meta = $this->bookingRentalTimestamps($item);
      if ($rental_meta !== NULL) {
        $line['rental'] = [
          'timezone' => $tz_id,
        ] + $this->rentalUtcStringsToDisplayIso(
          $rental_meta['start_raw'],
          $rental_meta['end_raw'],
          $tz_id,
          (int) $item->id()
        );
      }
      else {
        $line['rental'] = NULL;
      }

      $line['court'] = NULL;
      if ($purchased instanceof ProductVariationInterface) {
        $court_node = \court_booking_variation_published_court_node($purchased);
        if ($court_node instanceof NodeInterface && !$court_node->access('view', $account)) {
          $court_node = NULL;
        }
        if ($court_node instanceof NodeInterface) {
          $nid = (int) $court_node->id();
          $thumb = CourtBookingVariationThumbnail::data($purchased, $this->fileUrlGenerator);
          $image = $thumb['url'] ?? NULL;
          $location = \court_booking_court_location_label($court_node);
          $court_payload = [
            'nid' => $nid,
            'title' => $court_node->getTitle(),
            'location' => $location !== '' ? $location : NULL,
            'image' => $image,
            'fields' => $this->serializeCourtNodeFields($court_node),
          ];
          $line['court'] = $court_payload;
          $courts[$nid] = $court_payload;
        }
      }

      $lines[] = $line;
    }

    return ['status' => 200, 'data' => [
      'order_id' => (int) $fresh->id(),
      'state' => $fresh->getState()->getId(),
      'total' => $fresh->getTotalPrice() ? $fresh->getTotalPrice()->__toString() : NULL,
      'line_items' => $lines,
      'courts' => array_values($courts),
    ]];
  }

  /**
   * Lists authenticated user's completed court bookings (upcoming or past).
   *
   * @param array<string, int|string> $params
   *   Keys: page, limit, q, sport_tid (optional).
   *
   * @return array{status: int, data: array}
   */
  public function buildMyBookingsResponse(AccountInterface $account, string $bucket, array $params): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    $bucket = mb_strtolower(trim($bucket));
    if (!in_array($bucket, ['upcoming', 'past'], TRUE)) {
      $bucket = 'upcoming';
    }
    $page = max(0, (int) ($params['page'] ?? 0));
    $limit = (int) ($params['limit'] ?? 10);
    if ($limit <= 0) {
      $limit = 10;
    }
    $limit = min($limit, 50);
    $q = mb_strtolower(trim((string) ($params['q'] ?? '')));
    $sport_tid = max(0, (int) ($params['sport_tid'] ?? 0));

    $cb_config = $this->configFactory->get('court_booking.settings');
    $order_type_id = (string) ($cb_config->get('order_type_id') ?: 'default');
    $store = $this->currentStore->getStore();

    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $order_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', (int) $account->id())
      ->condition('state', 'completed')
      ->condition('type', $order_type_id)
      ->sort('order_id', 'DESC');
    if ($store) {
      $query->condition('store_id', $store->id());
    }
    $order_ids = $query->execute();
    if (!$order_ids) {
      return ['status' => 200, 'data' => $this->buildMyBookingsPagerResponse([], $page, $limit)];
    }

    $orders = $order_storage->loadMultiple($order_ids);
    $variation_orders = $this->completedOrderIdsByVariation($orders);
    $now = \Drupal::time()->getRequestTime();
    $tz_id = $this->siteTimezoneId();
    $candidates = [];

    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      foreach ($order->getItems() as $item) {
        $rental_meta = $this->bookingRentalTimestamps($item);
        if ($rental_meta === NULL) {
          continue;
        }
        $end_ts = $rental_meta['end_ts'];
        $is_upcoming = $end_ts > $now;
        if (($bucket === 'upcoming' && !$is_upcoming) || ($bucket === 'past' && $is_upcoming)) {
          continue;
        }
        $purchased = $item->getPurchasedEntity();
        if (!$purchased instanceof ProductVariationInterface) {
          continue;
        }
        $variation_id = (int) $purchased->id();
        if ($sport_tid > 0 && \court_booking_sport_tid_for_variation($purchased) !== $sport_tid) {
          continue;
        }
        $court_node = \court_booking_variation_published_court_node($purchased);
        if ($court_node instanceof NodeInterface && !$court_node->access('view', $account)) {
          $court_node = NULL;
        }
        $title = $court_node ? $court_node->getTitle() : (string) $item->getTitle();
        $location = $court_node ? \court_booking_court_location_label($court_node) : '';
        if ($q !== '' && !$this->bookingRowMatchesQuery($q, $title, $location)) {
          continue;
        }
        $thumb = CourtBookingVariationThumbnail::data($purchased, $this->fileUrlGenerator);
        $image = $thumb['url'] ?? NULL;
        $start_raw = $rental_meta['start_raw'];
        $end_raw = $rental_meta['end_raw'];
        $rental = [
          'timezone' => $tz_id,
        ];
        $rental += $this->rentalUtcStringsToDisplayIso($start_raw, $end_raw, $tz_id, (int) $item->id());

        $row = [
          'order_id' => (int) $order->id(),
          'order_ids' => $variation_id ? ($variation_orders[$variation_id] ?? [(int) $order->id()]) : [(int) $order->id()],
          'order_item_id' => (int) $item->id(),
          'variation_id' => $variation_id,
          'title' => $title,
          'quantity' => (string) $item->getQuantity(),
          'rental' => $rental,
          'location' => $location !== '' ? $location : NULL,
          'image' => $image,
          'state' => $order->getState()->getId(),
          '_sort_start' => $rental_meta['start_ts'],
        ];
        if ($court_node instanceof NodeInterface) {
          $row['nid'] = (int) $court_node->id();
          $row['fields'] = $this->serializeCourtNodeFields($court_node);
        }
        else {
          $row['fields'] = [];
        }
        $candidates[] = $row;
      }
    }

    usort($candidates, static function (array $a, array $b) use ($bucket): int {
      $sa = (int) ($a['_sort_start'] ?? 0);
      $sb = (int) ($b['_sort_start'] ?? 0);
      return $bucket === 'past' ? $sb <=> $sa : $sa <=> $sb;
    });

    foreach ($candidates as &$row) {
      unset($row['_sort_start']);
    }
    unset($row);

    return ['status' => 200, 'data' => $this->buildMyBookingsPagerResponse($candidates, $page, $limit)];
  }

  /**
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $orders
   *
   * @return array<int, int[]>
   */
  private function completedOrderIdsByVariation(array $orders): array {
    $map = [];
    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      if ($order->getState()->getId() !== 'completed') {
        continue;
      }
      $oid = (int) $order->id();
      foreach ($order->getItems() as $item) {
        $purchased = $item->getPurchasedEntity();
        if (!$purchased instanceof ProductVariationInterface) {
          continue;
        }
        if (!$item->hasField('field_cbat_rental_date') || $item->get('field_cbat_rental_date')->isEmpty()) {
          continue;
        }
        $vid = (int) $purchased->id();
        $map[$vid][$oid] = TRUE;
      }
    }
    $out = [];
    foreach ($map as $vid => $oids) {
      $ids = array_keys($oids);
      sort($ids, SORT_NUMERIC);
      $out[$vid] = $ids;
    }
    return $out;
  }

  /**
   * @return array{start_ts: int, end_ts: int, start_raw: string, end_raw: string}|null
   */
  private function bookingRentalTimestamps(OrderItemInterface $item): ?array {
    if (!$item->hasField('field_cbat_rental_date') || $item->get('field_cbat_rental_date')->isEmpty()) {
      return NULL;
    }
    $val = $item->get('field_cbat_rental_date')->first()->getValue();
    $start_s = trim((string) ($val['value'] ?? ''));
    $end_s = trim((string) ($val['end_value'] ?? ''));
    if ($start_s === '' || $end_s === '') {
      return NULL;
    }
    try {
      $start_utc = DateTimeHelper::normalizeUtc(DateTimeHelper::parseUtc($start_s, FALSE));
      $end_utc = DateTimeHelper::normalizeUtc(DateTimeHelper::parseUtc($end_s, FALSE));
      return [
        'start_ts' => $start_utc->getTimestamp(),
        'end_ts' => $end_utc->getTimestamp(),
        'start_raw' => $start_s,
        'end_raw' => $end_s,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->warning('my-bookings: rental parse failed for order_item=@id: @msg', [
        '@id' => (string) $item->id(),
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  private function bookingRowMatchesQuery(string $q, string $title, string $location): bool {
    $haystack = mb_strtolower($title . ' ' . $location);
    return $q === '' || str_contains($haystack, $q);
  }

  /**
   * @return array{start?: string, end?: string}
   */
  private function rentalUtcStringsToDisplayIso(string $value, string $end_value, string $tz_id, int $order_item_id): array {
    $out = [];
    try {
      $tz = new \DateTimeZone($tz_id);
    }
    catch (\Throwable $e) {
      $this->logger->warning('my-bookings: invalid timezone @tz for order_item=@id: @msg', [
        '@tz' => $tz_id,
        '@id' => (string) $order_item_id,
        '@msg' => $e->getMessage(),
      ]);
      return $out;
    }
    foreach ([['raw' => $value, 'key' => 'start'], ['raw' => $end_value, 'key' => 'end']] as $spec) {
      $raw = trim($spec['raw']);
      if ($raw === '') {
        continue;
      }
      try {
        $utc = DateTimeHelper::normalizeUtc(DateTimeHelper::parseUtc($raw, FALSE));
        $out[$spec['key']] = $utc->setTimezone($tz)->format(DATE_ATOM);
      }
      catch (\Throwable $e) {
        $this->logger->warning('my-bookings: could not format @key for order_item=@id: @msg', [
          '@key' => $spec['key'],
          '@id' => (string) $order_item_id,
          '@msg' => $e->getMessage(),
        ]);
      }
    }
    return $out;
  }

  private function siteTimezoneId(): string {
    $tz = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?? 'UTC');
    return $tz !== '' ? $tz : 'UTC';
  }

  private function formatUtcToSiteIso(\DateTimeImmutable $utc): string {
    return $utc->setTimezone(new \DateTimeZone($this->siteTimezoneId()))->format(DATE_ATOM);
  }

  private function formatUtcStringToSiteIso(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    try {
      $utc = DateTimeHelper::normalizeUtc(DateTimeHelper::parseUtc($raw, FALSE));
      return $this->formatUtcToSiteIso($utc);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  private function normalizeApiDateTimeInputToUtc(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    try {
      if (!preg_match('/(?:Z|[+\-]\d{2}:\d{2})$/', $raw)) {
        $local = new \DateTimeImmutable($raw, new \DateTimeZone($this->siteTimezoneId()));
        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
      }
      $dt = new \DateTimeImmutable($raw);
      return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @return array{rows: array<int, mixed>, pager: array<string, mixed>}
   */
  private function buildMyBookingsPagerResponse(array $rows, int $page, int $limit): array {
    $total = count($rows);
    $total_pages = $limit > 0 ? (int) ceil($total / $limit) : 1;
    if ($total_pages <= 0) {
      $total_pages = 1;
    }
    $offset = $page * $limit;
    $paged_rows = $limit > 0 ? array_slice($rows, $offset, $limit) : $rows;
    return [
      'rows' => array_values($paged_rows),
      'pager' => [
        'current_page' => $page,
        'total_items' => (string) $total,
        'total_pages' => $total_pages,
        'items_per_page' => $limit,
      ],
    ];
  }

  /**
   * @return array<string, array<int, array<string, mixed>>>
   */
  private function serializeCourtNodeFields(NodeInterface $node): array {
    $fields = [];
    foreach ($node->getFields() as $field_name => $items) {
      $definition = $items->getFieldDefinition();
      $storage_definition = $definition->getFieldStorageDefinition();
      if ($storage_definition->isBaseField() || $this->isSkippedCourtNodeField($field_name)) {
        continue;
      }
      $values = [];
      foreach ($items as $item) {
        $value = $item->getValue();
        if (isset($item->entity) && $item->entity instanceof FileInterface) {
          $value['url'] = $this->fileUrlGenerator->generateAbsoluteString($item->entity->getFileUri());
        }
        $values[] = $value;
      }
      $fields[$field_name] = $values;
    }
    ksort($fields);
    return $fields;
  }

  private function isSkippedCourtNodeField(string $field_name): bool {
    $skipped = [
      'nid',
      'uuid',
      'vid',
      'type',
      'revision_timestamp',
      'revision_uid',
      'revision_log',
      'revision_default',
      'revision_translation_affected',
      'langcode',
      'default_langcode',
      'status',
      'uid',
      'title',
      'created',
      'changed',
      'promote',
      'sticky',
      'path',
    ];
    return in_array($field_name, $skipped, TRUE) || str_starts_with($field_name, 'revision_');
  }

}
