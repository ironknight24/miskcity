<?php

namespace Drupal\court_booking\Form;

use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\commerce_bat\Entity\BatAvailabilityProfileInterface;
use Drupal\court_booking\CourtBookingRegional;
use Drupal\court_booking\CourtBookingSlotBlockOverlapValidator;
use Drupal\court_booking\CourtBookingSportSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin: block a lesson time range via Commerce BAT blockout events.
 */
final class SlotManagementForm extends FormBase {

  public function __construct(
    protected AvailabilityManagerInterface $availabilityManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CourtBookingSlotBlockOverlapValidator $slotBlockOverlapValidator,
    protected CourtBookingSportSettings $sportSettings,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_bat.availability_manager'),
      $container->get('entity_type.manager'),
      $container->get('court_booking.slot_block_overlap_validator'),
      $container->get('court_booking.sport_settings'),
      $container->get('cache_tags.invalidator'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'court_booking_slot_management';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = $this->eligibleVariationOptions();
    $commerce_bat_blockout = Url::fromRoute('commerce_bat.blockout')->toString();

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t(
        'Blocks a time range on the selected court so it cannot be booked. This creates a Commerce BAT <em>blockout</em> event (same mechanism as <a href=":url">Create blocking event</a> under Commerce BAT). Times use the effective timezone for your account and site regional settings.',
        [':url' => $commerce_bat_blockout]
      ) . '</p>',
    ];

    $form['variation_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Court'),
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#description' => $this->t('Only courts that appear on the public booking page (mapped, published, linked to a published court node, lesson mode).'),
    ];

    $form['block_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Date'),
      '#required' => TRUE,
    ];

    $form['time_start'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Start time'),
      '#size' => 8,
      '#required' => TRUE,
      '#default_value' => '06:00',
      '#description' => $this->t('24-hour local time, format @format.', ['@format' => 'HH:MM']),
    ];

    $form['time_end'] = [
      '#type' => 'textfield',
      '#title' => $this->t('End time'),
      '#size' => 8,
      '#required' => TRUE,
      '#default_value' => '23:00',
      '#description' => $this->t('Must be after start time on the same calendar day.'),
    ];

    // Keep blockout quantity fixed at 1 while hiding it from the UI.
    $form['quantity'] = [
      '#type' => 'value',
      '#value' => 1,
    ];

    if ($options === []) {
      $form['variation_id']['#access'] = FALSE;
      $form['block_date']['#access'] = FALSE;
      $form['time_start']['#access'] = FALSE;
      $form['time_end']['#access'] = FALSE;
      $form['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p><em>' . $this->t('No eligible courts are configured. Add sport mappings and ensure each court has a published court node.') . '</em></p>',
      ];
    }

    $tz = CourtBookingRegional::effectiveTimeZoneId($this->configFactory(), $this->currentUser());
    $form['timezone_hint'] = [
      '#type' => 'item',
      '#title' => $this->t('Timezone'),
      '#markup' => Html::escape($tz),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Block time slot'),
      '#button_type' => 'primary',
      '#access' => $options !== [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->eligibleVariationOptions() === []) {
      return;
    }

    $date = trim((string) $form_state->getValue('block_date'));
    $time_start = trim((string) $form_state->getValue('time_start'));
    $time_end = trim((string) $form_state->getValue('time_end'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      $form_state->setErrorByName('block_date', $this->t('Enter a valid date.'));
      return;
    }

    $start_parts = $this->parseHourMinute($time_start);
    $end_parts = $this->parseHourMinute($time_end);
    if ($start_parts === NULL) {
      $form_state->setErrorByName('time_start', $this->t('Use 24-hour time as @format.', ['@format' => 'HH:MM']));
      return;
    }
    if ($end_parts === NULL) {
      $form_state->setErrorByName('time_end', $this->t('Use 24-hour time as @format.', ['@format' => 'HH:MM']));
      return;
    }

    $tz_name = CourtBookingRegional::effectiveTimeZoneId($this->configFactory(), $this->currentUser());
    try {
      $tz = new \DateTimeZone($tz_name);
    }
    catch (\Throwable $e) {
      $tz = new \DateTimeZone('UTC');
    }

    [$sh, $sm] = $start_parts;
    [$eh, $em] = $end_parts;
    $start = new \DateTimeImmutable($date . ' ' . sprintf('%02d:%02d:00', $sh, $sm), $tz);
    $end = new \DateTimeImmutable($date . ' ' . sprintf('%02d:%02d:00', $eh, $em), $tz);

    if ($end <= $start) {
      $form_state->setErrorByName('time_end', $this->t('End time must be after start time.'));
      return;
    }
    $now = new \DateTimeImmutable('now', $tz);
    if ($start < $now) {
      $form_state->setErrorByName('block_date', $this->t('Cannot block slots in the past. Choose a future date/time.'));
      return;
    }

    $form_state->set('parsed_start', $start);
    $form_state->set('parsed_end', $end);

    $vid = (int) $form_state->getValue('variation_id');
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
    if (!$variation instanceof ProductVariationInterface) {
      $form_state->setErrorByName('variation_id', $this->t('Invalid court selection.'));
      return;
    }
    if ($this->isVariationUnitShared($variation)) {
      $form_state->setErrorByName('variation_id', $this->t(
        'This court is currently mapped to a shared BAT unit pool. Blocking one court would affect other courts too. Run database updates (for example, drush updb) to apply per-variation lesson capacity before creating slot blocks.',
      ));
      return;
    }
    $rules = $this->sportSettings->getMergedForVariation($variation);
    $operating_window = $this->resolveRulesOperatingWindow($rules);
    if ($operating_window === NULL) {
      $form_state->setErrorByName(
        'variation_id',
        $this->t('Could not resolve operating hours from court booking settings for this court. Configure booking start/end times before creating slot blocks.')
      );
      return;
    }
    if (!$this->isWithinOperatingWindow($start, $end, $operating_window['start'], $operating_window['end'])) {
      $form_state->setErrorByName(
        'time_start',
        $this->t('Selected range is outside configured court operating hours (@start-@end).', [
          '@start' => $operating_window['start'],
          '@end' => $operating_window['end'],
        ])
      );
      return;
    }
    $duration_error = $this->validateMaxBookingDuration($start, $end, $rules);
    if ($duration_error !== NULL) {
      $form_state->setErrorByName('time_end', $duration_error);
      return;
    }
    // Keep profile checks as a secondary safety guard when a schedule profile exists.
    $profile_window = $this->resolveVariationOperatingWindow($variation, $start);
    if ($profile_window !== NULL && !$this->isWithinOperatingWindow($start, $end, $profile_window['start'], $profile_window['end'])) {
      $form_state->setErrorByName(
        'time_start',
        $this->t('Selected range is outside variation availability profile hours (@start-@end).', [
          '@start' => $profile_window['start'],
          '@end' => $profile_window['end'],
        ])
      );
      return;
    }

    $quantity = max(1, (int) $form_state->getValue('quantity'));
    $capacity = $this->availabilityManager->getCapacity($variation);
    $mode = $this->availabilityManager->getModeForVariation($variation);
    $seat_quantity = $quantity;
    if ($mode === 'lesson') {
      $seats_per_qty = max(1, $this->availabilityManager->getLessonSeatsPerQty($variation));
      $seat_quantity = $quantity * $seats_per_qty;
    }
    if ($capacity > 0 && $seat_quantity > $capacity) {
      if ($mode === 'lesson') {
        $form_state->setErrorByName('variation_id', $this->t(
          'Requested seats (@seats) exceed capacity (@cap). Reduce the quantity.',
          ['@seats' => $seat_quantity, '@cap' => $capacity]
        ));
      }
      else {
        $form_state->setErrorByName('variation_id', $this->t(
          'Quantity (@qty) exceeds capacity (@cap). Reduce the quantity.',
          ['@qty' => $quantity, '@cap' => $capacity]
        ));
      }
      return;
    }

    if ($this->slotBlockOverlapValidator->hasOverlappingBlockout($variation, $start, $end)) {
      $form_state->setErrorByName(
        'time_start',
        $this->t('This date and time range overlaps an existing block for this court. Remove or edit the existing block in Commerce BAT, or choose a different time range.'),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->eligibleVariationOptions() === []) {
      return;
    }

    /** @var \DateTimeImmutable $start */
    $start = $form_state->get('parsed_start');
    /** @var \DateTimeImmutable $end */
    $end = $form_state->get('parsed_end');
    if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
      $this->messenger()->addError($this->t('Could not parse the time range.'));
      return;
    }

    $vid = (int) $form_state->getValue('variation_id');
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($vid);
    if (!$variation instanceof ProductVariationInterface) {
      $this->messenger()->addError($this->t('Invalid court selection.'));
      return;
    }
    if ($this->isVariationUnitShared($variation)) {
      $this->messenger()->addError($this->t(
        'Cannot create slot block while this court is mapped to a shared BAT unit pool. Run database updates (for example, drush updb) to apply per-variation lesson capacity, then try again.',
      ));
      return;
    }

    $quantity = max(1, (int) $form_state->getValue('quantity'));
    $context = [
      'source' => 'admin_blockout',
      'blockout_state' => $this->availabilityManager->getBlockoutStateForVariation($variation),
      'calendar_selection' => FALSE,
    ];

    if (!$this->availabilityManager->createBlockingEvent($variation, $start, $end, $quantity, $context)) {
      $this->messenger()->addError($this->t('Could not create the blockout. Check Commerce BAT configuration and logs.'));
      return;
    }

    $this->messenger()->addStatus($this->t('Time slot blocked for @label (@start – @end).', [
      '@label' => $variation->label(),
      '@start' => $start->format('Y-m-d H:i'),
      '@end' => $end->format('Y-m-d H:i'),
    ]));
    // Ensure availability JSON/render caches see the new BAT block immediately.
    $this->cacheTagsInvalidator->invalidateTags(array_values(array_unique(array_merge(
      $variation->getCacheTags(),
      $this->config('commerce_bat.settings')->getCacheTags(),
      ['bat_event_list'],
    ))));
    $form_state->setRebuild();
  }

  /**
   * @return array{0: int, 1: int}|null
   *   Hour and minute, or NULL if invalid.
   */
  private function parseHourMinute(string $hm): ?array {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hm, $m)) {
      return NULL;
    }
    $h = (int) $m[1];
    $min = (int) $m[2];
    if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
      return NULL;
    }

    return [$h, $min];
  }

  /**
   * Parses HH:MM to minutes from midnight.
   */
  private function parseHmToMinutes(string $hm): ?int {
    $parts = $this->parseHourMinute($hm);
    if ($parts === NULL) {
      return NULL;
    }
    [$h, $m] = $parts;
    return $h * 60 + $m;
  }

  /**
   * Resolves booking-day operating window from merged court-booking rules.
   *
   * @param array<string, mixed> $rules
   *
   * @return array{start: string, end: string}|null
   */
  private function resolveRulesOperatingWindow(array $rules): ?array {
    $start = trim((string) ($rules['booking_day_start'] ?? ''));
    $end = trim((string) ($rules['booking_day_end'] ?? ''));
    $start_m = $this->parseHmToMinutes($start);
    $end_m = $this->parseHmToMinutes($end);
    if ($start_m === NULL || $end_m === NULL || $end_m <= $start_m) {
      return NULL;
    }
    return [
      'start' => $start,
      'end' => $end,
    ];
  }

  /**
   * Validates selected duration against merged max-booking rules.
   *
   * @param array<string, mixed> $rules
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   Error message when invalid, NULL otherwise.
   */
  private function validateMaxBookingDuration(\DateTimeImmutable $start, \DateTimeImmutable $end, array $rules) {
    $duration_minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    if ($duration_minutes <= 0) {
      return $this->t('End time must be after start time.');
    }
    $buffer_minutes = max(0, min(180, (int) ($rules['buffer_minutes'] ?? 0)));
    $play_minutes = $duration_minutes;
    if ($buffer_minutes > 0) {
      $play_minutes -= $buffer_minutes;
      if ($play_minutes <= 0) {
        return $this->t('The selected window is too short for the configured buffer plus play time.');
      }
    }
    $max_hours = max(1, min(24, (int) ($rules['max_booking_hours'] ?? 4)));
    if ($play_minutes > ($max_hours * 60)) {
      return $this->t('Selected duration exceeds maximum booking limit of @hours hour(s) for this court.', [
        '@hours' => $max_hours,
      ]);
    }
    return NULL;
  }

  /**
   * Resolves the variation availability profile used for operating hours.
   */
  private function resolveVariationScheduleProfile(ProductVariationInterface $variation): ?BatAvailabilityProfileInterface {
    foreach (['field_cbat_schedule', 'field_schedule'] as $field_name) {
      if (!$variation->hasField($field_name) || $variation->get($field_name)->isEmpty()) {
        continue;
      }
      $profile = $variation->get($field_name)->entity;
      if ($profile instanceof BatAvailabilityProfileInterface) {
        return $profile;
      }
    }
    return NULL;
  }

  /**
   * Resolves day operating window from variation availability profile.
   *
   * @return array{start: string, end: string}|null
   */
  private function resolveVariationOperatingWindow(ProductVariationInterface $variation, \DateTimeImmutable $local_date): ?array {
    $profile = $this->resolveVariationScheduleProfile($variation);
    if (!$profile) {
      return NULL;
    }

    $seasonal = $profile->getOperatingHoursForDate($local_date, 'lesson');
    if (is_array($seasonal) && !empty($seasonal['start']) && !empty($seasonal['end'])) {
      return [
        'start' => (string) $seasonal['start'],
        'end' => (string) $seasonal['end'],
      ];
    }
    $weekly_rules = (array) $profile->getWeeklyRules();
    $weekday = strtolower($local_date->format('D'));
    foreach ($weekly_rules as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      if (($rule['day'] ?? '') !== $weekday) {
        continue;
      }
      $start = trim((string) ($rule['start_time'] ?? ''));
      $end = trim((string) ($rule['end_time'] ?? ''));
      if ($this->parseHmToMinutes($start) === NULL || $this->parseHmToMinutes($end) === NULL) {
        continue;
      }
      if ($this->parseHmToMinutes($end) <= $this->parseHmToMinutes($start)) {
        continue;
      }
      return ['start' => $start, 'end' => $end];
    }
    return NULL;
  }

  /**
   * TRUE when selected local window is inside operating hours.
   */
  private function isWithinOperatingWindow(
    \DateTimeImmutable $start,
    \DateTimeImmutable $end,
    string $open_hm,
    string $close_hm,
  ): bool {
    $open_m = $this->parseHmToMinutes($open_hm);
    $close_m = $this->parseHmToMinutes($close_hm);
    if ($open_m === NULL || $close_m === NULL || $close_m <= $open_m) {
      return FALSE;
    }
    $start_m = (int) $start->format('G') * 60 + (int) $start->format('i');
    $end_m = (int) $end->format('G') * 60 + (int) $end->format('i');
    if ($end_m < $start_m) {
      return FALSE;
    }
    return $start_m >= $open_m && $end_m <= $close_m;
  }

  /**
   * TRUE if this variation resolves to a BAT unit mapped to multiple variations.
   */
  private function isVariationUnitShared(ProductVariationInterface $variation): bool {
    $unit = $this->availabilityManager->getUnitForVariation($variation);
    if (!is_object($unit) || !method_exists($unit, 'id')) {
      return FALSE;
    }
    $unit_id = (int) $unit->id();
    if ($unit_id <= 0 || !$this->database->schema()->tableExists('commerce_bat_variation_unit')) {
      return FALSE;
    }
    $mapped_count = (int) $this->database->select('commerce_bat_variation_unit', 'm')
      ->condition('m.unit_id', $unit_id)
      ->countQuery()
      ->execute()
      ->fetchField();

    return $mapped_count > 1;
  }

  /**
   * Variations eligible for the public booking page, same filters as listing.
   *
   * @return array<int|string, string>
   *   Options for #type select: id => label.
   */
  private function eligibleVariationOptions(): array {
    $config = $this->config('court_booking.settings');
    $mappings = $config->get('sport_mappings') ?: [];
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    $options = [];
    $included = [];
    $skips = [
      'not_configured' => 0,
      'no_published_court_node' => 0,
      'not_lesson' => 0,
      'duplicate' => 0,
    ];

    foreach ($mappings as $row) {
      $product_id = (int) ($row['product_id'] ?? 0);
      $legacy_vids = array_map('intval', $row['variation_ids'] ?? []);
      $variation_entities = [];
      if ($product_id > 0) {
        $product = $product_storage->load($product_id);
        if ($product instanceof ProductInterface) {
          foreach ($product->getVariations() as $v) {
            if ($v->isPublished()) {
              $variation_entities[] = $v;
            }
          }
        }
      }
      else {
        foreach ($legacy_vids as $vid) {
          $variation = $variation_storage->load($vid);
          if ($variation && $variation->isPublished()) {
            $variation_entities[] = $variation;
          }
        }
      }

      foreach ($variation_entities as $variation) {
        if (!court_booking_variation_is_configured($variation)) {
          $skips['not_configured']++;
          continue;
        }
        if (!court_booking_variation_has_published_court_node($variation)) {
          $skips['no_published_court_node']++;
          continue;
        }
        if ($this->availabilityManager->getModeForVariation($variation) !== 'lesson') {
          $skips['not_lesson']++;
          continue;
        }
        $id = (string) $variation->id();
        if (isset($options[$id])) {
          $skips['duplicate']++;
          continue;
        }
        $court = \Drupal\court_booking\CourtBookingVariationThumbnail::courtNode($variation);
        $court_title = $court ? $court->label() : '';
        $court_location = function_exists('court_booking_court_location_label')
          ? \court_booking_court_location_label($court)
          : '';
        $label = $court_title !== '' ? $court_title : $variation->getTitle();
        $options[$id] = $label;
        $included[] = [
          'variationId' => (int) $variation->id(),
          'variationTitle' => $variation->getTitle(),
          'courtTitle' => $court_title,
          'courtLocation' => $court_location,
          'renderedLabel' => $label,
        ];
      }
    }

    natcasesort($options);

    return $options;
  }

}
