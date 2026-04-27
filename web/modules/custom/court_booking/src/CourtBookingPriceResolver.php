<?php

namespace Drupal\court_booking;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Resolves play-time unit price from variation + peak/weekend schedule config.
 *
 * Only **time_band** rules apply (weekdays + local clock + surcharge field). Slot
 * start local time uses half-open [start_hm, end_hm).
 *
 * @see \Drupal\court_booking\CourtBookingSlotBooking::applyRentalAndPrice()
 */
final class CourtBookingPriceResolver {

  public const FIELD_PEAK = 'field_peak_hours_pricing';

  public const FIELD_WEEKEND = 'field_weekend_pricing';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Per billing-unit price (one lesson slot length of play).
   *
   * @return \Drupal\commerce_price\Price|null
   *   NULL when variation has no base price.
   */
  public function resolvePerBillingUnitPrice(
    ProductVariationInterface $variation,
    \DateTimeImmutable $slotStartUtc,
    AccountInterface $account,
  ): ?Price {
    $base = $variation->getPrice();
    if (!$base instanceof Price) {
      return NULL;
    }

    $price = $base;
    $vid = (int) $variation->id();
    $rules = $this->enabledRulesForVariation($vid);
    if ($rules === []) {
      return $price;
    }

    $tz_id = CourtBookingRegional::effectiveTimeZoneId($this->configFactory, $account);
    try {
      $tz = new \DateTimeZone($tz_id);
    }
    catch (\Throwable) {
      $tz = new \DateTimeZone('UTC');
    }
    $local = $slotStartUtc->setTimezone($tz);
    $weekday = (int) $local->format('N');
    $minutes = (int) $local->format('G') * 60 + (int) $local->format('i');

    $log = function (string $message, array $context): void {
      $this->loggerFactory->get('court_booking')->warning($message, $context);
    };

    $timeRules = array_values(array_filter($rules, static fn (array $r): bool => ($r['rule_type'] ?? '') === 'time_band'));
    if ($timeRules !== []) {
      $windows = CourtBookingPricingRulesEngine::timeBandRulesToWindowPickList($timeRules);
      $picked = self::pickWinningWindowFromList($weekday, $minutes, $windows);
      if (is_array($picked)) {
        $next = CourtBookingPricingRulesEngine::applyConfiguredModifier($price, $variation, $picked, $log);
        if ($next instanceof Price) {
          $price = $next;
        }
      }
    }

    return $price;
  }

  /**
   * Total line unit price for the cart: per-billing-unit × billing_units.
   */
  public function resolveScaledLinePrice(
    ProductVariationInterface $variation,
    \DateTimeImmutable $slotStartUtc,
    int $billing_units,
    AccountInterface $account,
  ): ?Price {
    if ($billing_units < 1) {
      return NULL;
    }
    $unit = $this->resolvePerBillingUnitPrice($variation, $slotStartUtc, $account);
    if (!$unit instanceof Price) {
      return NULL;
    }

    return $unit->multiply((string) $billing_units);
  }

  /**
   * Surcharge from the winning time_band rule only (surcharge_field modifier), else zero.
   *
   * Same time-window pick as resolvePerBillingUnitPrice; non–time_band rules are omitted.
   */
  public function resolveSurchargePrice(
    ProductVariationInterface $variation,
    \DateTimeImmutable $slotStartUtc,
    AccountInterface $account,
  ): ?Price {
    $base = $variation->getPrice();
    if (!$base instanceof Price) {
      return NULL;
    }
    $zero = new Price('0', $base->getCurrencyCode());
    $vid = (int) $variation->id();
    $rules = $this->enabledRulesForVariation($vid);
    $timeRules = array_values(array_filter($rules, static fn (array $r): bool => ($r['rule_type'] ?? '') === 'time_band'));
    if ($timeRules === []) {
      return NULL;
    }
    $tz_id = CourtBookingRegional::effectiveTimeZoneId($this->configFactory, $account);
    try {
      $tz = new \DateTimeZone($tz_id);
    }
    catch (\Throwable) {
      $tz = new \DateTimeZone('UTC');
    }
    $local = $slotStartUtc->setTimezone($tz);
    $weekday = (int) $local->format('N');
    $minutes = (int) $local->format('G') * 60 + (int) $local->format('i');
    $windows = CourtBookingPricingRulesEngine::timeBandRulesToWindowPickList($timeRules);
    $picked = self::pickWinningWindowFromList($weekday, $minutes, $windows);
    if (!is_array($picked)) {
      return NULL;
    }
    $rule = $picked['_full_rule'] ?? $picked;
    $kind = strtolower(trim((string) ($rule['modifier_kind'] ?? 'surcharge_field')));
    if ($kind !== 'surcharge_field') {
      return $zero;
    }
    $src = strtolower(trim((string) ($rule['surcharge_field'] ?? $rule['surcharge_source'] ?? 'none')));
    if ($src === 'none' || $src === '') {
      return $zero;
    }
    $field = match ($src) {
      'peak' => self::FIELD_PEAK,
      'weekend' => self::FIELD_WEEKEND,
      default => NULL,
    };
    if ($field === NULL || !$variation->hasField($field) || $variation->get($field)->isEmpty()) {
      return NULL;
    }
    $value = $variation->get($field)->first()?->getValue();
    if (!is_array($value) || !isset($value['number'], $value['currency_code'])) {
      return NULL;
    }
    $currency = strtoupper(trim((string) $value['currency_code']));
    if ($currency !== '' && $currency !== $base->getCurrencyCode()) {
      $this->loggerFactory->get('court_booking')->warning(
        'Court booking: ignoring surcharge for variation @id (currency @s != base @b).',
        [
          '@id' => (string) $variation->id(),
          '@s' => $currency,
          '@b' => $base->getCurrencyCode(),
        ],
      );

      return NULL;
    }

    try {
      return new Price((string) $value['number'], $currency !== '' ? $currency : $base->getCurrencyCode());
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Whether this variation has any enabled dynamic pricing rules or surcharge fields.
   */
  public function variationHasPricingWindows(int $variation_id): bool {
    if ($this->enabledRulesForVariation($variation_id) !== []) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Whether any dynamic pricing rules exist for the variation (ignores field-only surcharges).
   */
  public function variationHasDynamicPricingRules(int $variation_id): bool {
    return $this->enabledRulesForVariation($variation_id) !== [];
  }

  /**
   * @return array{peak: ?\Drupal\commerce_price\Price, weekend: ?\Drupal\commerce_price\Price}
   */
  public function variationSurchargeFieldPrices(ProductVariationInterface $variation): array {
    $out = ['peak' => NULL, 'weekend' => NULL];
    foreach (['peak' => self::FIELD_PEAK, 'weekend' => self::FIELD_WEEKEND] as $key => $field) {
      if (!$variation->hasField($field) || $variation->get($field)->isEmpty()) {
        continue;
      }
      $value = $variation->get($field)->first()?->getValue();
      if (!is_array($value) || !isset($value['number'], $value['currency_code'])) {
        continue;
      }
      try {
        $out[$key] = new Price((string) $value['number'], (string) $value['currency_code']);
      }
      catch (\Throwable) {
        // Malformed field value; skip this surcharge key.
      }
    }

    return $out;
  }

  /**
   * @param array<int, array<string, mixed>> $windows
   *   Window rows for time matching; may include _full_rule for dynamic modifiers.
   *
   * @return array<string, mixed>|null
   *   The winning window row or NULL.
   */
  public static function pickWinningWindowFromList(int $php_weekday_1_to_7, int $minutes_from_midnight, array $windows): ?array {
    $candidates = [];
    foreach ($windows as $idx => $w) {
      if (!is_array($w)) {
        continue;
      }
      $days = $w['days_of_week'] ?? [];
      if (!is_array($days)) {
        $days = [];
      }
      $days = array_values(array_unique(array_map('intval', $days)));
      $days = array_filter($days, static fn (int $d): bool => $d >= 1 && $d <= 7);
      $days = array_values($days);
      if ($days !== [] && !in_array($php_weekday_1_to_7, $days, TRUE)) {
        continue;
      }
      $start_hm = trim((string) ($w['start_hm'] ?? ''));
      $end_hm = trim((string) ($w['end_hm'] ?? ''));
      $start_m = self::hmToMinutes($start_hm);
      $end_m = self::hmToMinutes($end_hm);
      if ($start_m === NULL || $end_m === NULL || $end_m <= $start_m) {
        continue;
      }
      if ($minutes_from_midnight < $start_m || $minutes_from_midnight >= $end_m) {
        continue;
      }
      $day_count = $days === [] ? 7 : count($days);
      $span = $end_m - $start_m;
      $candidates[] = [
        'window' => $w,
        'day_count' => $day_count,
        'span' => $span,
        'idx' => $idx,
      ];
    }
    if ($candidates === []) {
      return NULL;
    }
    usort($candidates, static function (array $a, array $b): int {
      if ($a['day_count'] !== $b['day_count']) {
        return $a['day_count'] <=> $b['day_count'];
      }
      if ($a['span'] !== $b['span']) {
        return $a['span'] <=> $b['span'];
      }

      return $a['idx'] <=> $b['idx'];
    });

    $win = $candidates[0]['window'];
    if (is_array($win) && !isset($win['surcharge_source']) && isset($win['surcharge_field'])) {
      $win['surcharge_source'] = $win['surcharge_field'];
    }

    return $win;
  }

  /**
   * @return array{
   *   basePriceAmount: string,
   *   basePriceCurrencyCode: string,
   *   peakSurchargeAmount: string,
   *   peakSurchargeCurrencyCode: string,
   *   weekendSurchargeAmount: string,
   *   weekendSurchargeCurrencyCode: string,
   *   hasTieredPricing: bool,
   *   hasDynamicPricingRules: bool,
   *   dynamicPricingRuleTypes: list<string>  (time_band only when rules exist)
   * }
   */
  public function variationPricingBootstrap(ProductVariationInterface $variation): array {
    $base = $variation->getPrice();
    $baseAmount = '';
    $baseCurrency = '';
    if ($base instanceof Price) {
      $baseAmount = $base->getNumber();
      $baseCurrency = $base->getCurrencyCode();
    }
    $sf = $this->variationSurchargeFieldPrices($variation);
    $peakAmount = '';
    $peakCurrency = '';
    if ($sf['peak'] instanceof Price) {
      $peakAmount = $sf['peak']->getNumber();
      $peakCurrency = $sf['peak']->getCurrencyCode();
    }
    $weekendAmount = '';
    $weekendCurrency = '';
    if ($sf['weekend'] instanceof Price) {
      $weekendAmount = $sf['weekend']->getNumber();
      $weekendCurrency = $sf['weekend']->getCurrencyCode();
    }
    $vid = (int) $variation->id();
    $ruleTypes = $this->distinctRuleTypesForVariation($vid);
    $hasRules = $ruleTypes !== [];
    $hasTiered = $hasRules
      || $sf['peak'] instanceof Price
      || $sf['weekend'] instanceof Price;

    return [
      'basePriceAmount' => $baseAmount,
      'basePriceCurrencyCode' => $baseCurrency,
      'peakSurchargeAmount' => $peakAmount,
      'peakSurchargeCurrencyCode' => $peakCurrency,
      'weekendSurchargeAmount' => $weekendAmount,
      'weekendSurchargeCurrencyCode' => $weekendCurrency,
      'hasTieredPricing' => $hasTiered,
      'hasDynamicPricingRules' => $hasRules,
      'dynamicPricingRuleTypes' => $ruleTypes,
    ];
  }

  public static function hmToMinutes(string $hm): ?int {
    $raw = trim($hm);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $raw)) {
      return NULL;
    }
    [$h, $m] = array_map('intval', explode(':', $raw, 2));

    return $h * 60 + $m;
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function enabledRulesForVariation(int $variation_id): array {
    foreach ($this->rawRulesConfig() as $row) {
      if (!is_array($row) || (int) ($row['variation_id'] ?? 0) !== $variation_id) {
        continue;
      }
      $rules = $row['rules'] ?? [];
      if (!is_array($rules)) {
        return [];
      }
      $out = [];
      foreach ($rules as $r) {
        if (!is_array($r)) {
          continue;
        }
        if (array_key_exists('enabled', $r) && empty($r['enabled'])) {
          continue;
        }
        $type = trim((string) ($r['rule_type'] ?? ''));
        if ($type !== 'time_band') {
          continue;
        }
        $out[] = $r;
      }

      return $out;
    }

    return [];
  }

  /**
   * @return list<string>
   */
  private function distinctRuleTypesForVariation(int $variation_id): array {
    $types = [];
    foreach ($this->enabledRulesForVariation($variation_id) as $r) {
      $t = trim((string) ($r['rule_type'] ?? ''));
      if ($t !== '') {
        $types[$t] = $t;
      }
    }

    return array_values($types);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function rawRulesConfig(): array {
    $c = $this->configFactory->get('court_booking.settings');
    $rules = $c->get('variation_pricing_rules');
    if (is_array($rules) && $rules !== []) {
      return array_values($rules);
    }
    $legacy = $c->get('variation_pricing_windows');
    if (is_array($legacy) && $legacy !== []) {
      return CourtBookingPricingRulesEngine::convertLegacyWindowsToRules($legacy);
    }

    return [];
  }

}
