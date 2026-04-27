<?php

namespace Drupal\court_booking;

use Drupal\commerce_price\Exception\CurrencyMismatchException;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Pure helpers for peak/weekend schedule rules (config shape, legacy window import).
 *
 * @see \Drupal\court_booking\CourtBookingPriceResolver
 */
final class CourtBookingPricingRulesEngine {

  /**
   * Converts legacy variation_pricing_windows config rows to variation_pricing_rules shape.
   *
   * @param array<int, mixed> $legacy
   *
   * @return array<int, array<string, mixed>>
   */
  public static function convertLegacyWindowsToRules(array $legacy): array {
    $out = [];
    foreach ($legacy as $row) {
      if (!is_array($row)) {
        continue;
      }
      $vid = (int) ($row['variation_id'] ?? 0);
      if ($vid <= 0) {
        continue;
      }
      $windows = $row['windows'] ?? [];
      if (!is_array($windows)) {
        continue;
      }
      $rules = [];
      foreach ($windows as $w) {
        if (!is_array($w)) {
          continue;
        }
        $src = strtolower(trim((string) ($w['surcharge_source'] ?? 'none')));
        if (!in_array($src, ['peak', 'weekend', 'none'], TRUE)) {
          $src = 'none';
        }
        $days = $w['days_of_week'] ?? [];
        $days = is_array($days) ? array_values(array_unique(array_filter(array_map('intval', $days), static fn (int $d): bool => $d >= 1 && $d <= 7))) : [];
        $rules[] = [
          'rule_type' => 'time_band',
          'enabled' => TRUE,
          'label' => trim((string) ($w['label'] ?? '')),
          'days_of_week' => $days,
          'start_hm' => trim((string) ($w['start_hm'] ?? '09:00')),
          'end_hm' => trim((string) ($w['end_hm'] ?? '17:00')),
          'modifier_kind' => 'surcharge_field',
          'surcharge_field' => $src,
          'percent_value' => '',
          'percent_direction' => 'add',
          'fixed_number' => '',
          'fixed_currency' => '',
          'fixed_direction' => 'add',
          'start_date' => '',
          'end_date' => '',
          'promo_start_date' => '',
          'promo_end_date' => '',
          'member_role_ids' => [],
          'percent_off' => '',
        ];
      }
      if ($rules !== []) {
        $out[] = [
          'variation_id' => $vid,
          'rules' => $rules,
        ];
      }
    }

    return $out;
  }

  /**
   * Inclusive Y-m-d range check (string compare valid for ISO dates).
   */
  public static function localDateInInclusiveRange(string $localYmd, string $start, string $end): bool {
    $localYmd = trim($localYmd);
    $start = trim($start);
    $end = trim($end);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $localYmd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
      return FALSE;
    }
    if ($end < $start) {
      return FALSE;
    }

    return $localYmd >= $start && $localYmd <= $end;
  }

  /**
   * Whether the account has any of the configured roles (machine names).
   *
   * @param list<string> $role_ids
   */
  public static function accountMatchesRoles(AccountInterface $account, array $role_ids): bool {
    $roles = array_filter(array_map('strval', $role_ids), static fn (string $r): bool => $r !== '');
    if ($roles === []) {
      return FALSE;
    }
    $have = $account->getRoles();

    return count(array_intersect($roles, $have)) > 0;
  }

  /**
   * Maps time_band rules to the shape expected by pickWinningWindowFromList().
   *
   * @param array<int, array<string, mixed>> $timeRules
   *
   * @return array<int, array<string, mixed>>
   */
  public static function timeBandRulesToWindowPickList(array $timeRules): array {
    $windows = [];
    foreach ($timeRules as $r) {
      if (!is_array($r)) {
        continue;
      }
      $days = $r['days_of_week'] ?? [];
      if (!is_array($days)) {
        $days = [];
      }
      $days = array_values(array_unique(array_filter(array_map('intval', $days), static fn (int $d): bool => $d >= 1 && $d <= 7)));
      $src = strtolower(trim((string) ($r['surcharge_field'] ?? $r['surcharge_source'] ?? 'none')));
      $windows[] = [
        'label' => trim((string) ($r['label'] ?? '')),
        'days_of_week' => $days,
        'start_hm' => trim((string) ($r['start_hm'] ?? '')),
        'end_hm' => trim((string) ($r['end_hm'] ?? '')),
        'surcharge_source' => in_array($src, ['peak', 'weekend', 'none'], TRUE) ? $src : 'none',
        '_full_rule' => $r,
      ];
    }

    return $windows;
  }

  /**
   * Applies time_band modifier_kind (surcharge_field, percent, fixed) to the price.
   *
   * Stored config after update 9023 uses surcharge_field only; other kinds remain
   * for defensive parsing of unmigrated data.
   *
   * @param array<string, mixed> $rule
   *   Full rule row (including _full_rule from picker when present).
   */
  public static function applyConfiguredModifier(
    Price $current,
    ProductVariationInterface $variation,
    array $rule,
    callable $logger,
  ): ?Price {
    $rule = $rule['_full_rule'] ?? $rule;
    $kind = strtolower(trim((string) ($rule['modifier_kind'] ?? 'surcharge_field')));

    return match ($kind) {
      'percent' => self::applyPercentModifier($current, $rule),
      'fixed_amount' => self::applyFixedModifier($current, $variation, $rule, $logger),
      default => self::applySurchargeFieldModifier($current, $variation, $rule, $logger),
    };
  }

  /**
   * Member discount as last step: multiply by (100 - percent_off) / 100.
   */
  public static function applyMemberPercentOff(Price $current, array $rule): ?Price {
    $off = trim((string) ($rule['percent_off'] ?? ''));
    if ($off === '' || !is_numeric($off)) {
      return $current;
    }
    $pct = (float) $off;
    if ($pct <= 0 || $pct > 100) {
      return $current;
    }
    $factor = (string) ((100.0 - $pct) / 100.0);

    try {
      return $current->multiply($factor);
    }
    catch (\Throwable) {
      return $current;
    }
  }

  private static function applyPercentModifier(Price $current, array $rule): ?Price {
    $raw = trim((string) ($rule['percent_value'] ?? ''));
    if ($raw === '' || !is_numeric($raw)) {
      return $current;
    }
    $p = (float) $raw;
    if ($p < 0 || $p > 500) {
      return $current;
    }
    $dir = strtolower(trim((string) ($rule['percent_direction'] ?? 'add')));
    $sign = $dir === 'subtract' ? -1.0 : 1.0;
    $factor = (string) (1.0 + $sign * ($p / 100.0));
    if ((float) $factor < 0.01) {
      return $current;
    }

    try {
      return $current->multiply($factor);
    }
    catch (\Throwable) {
      return $current;
    }
  }

  private static function applyFixedModifier(
    Price $current,
    ProductVariationInterface $variation,
    array $rule,
    callable $logger,
  ): ?Price {
    $num = trim((string) ($rule['fixed_number'] ?? ''));
    $ccy = strtoupper(trim((string) ($rule['fixed_currency'] ?? '')));
    if ($num === '' || !is_numeric($num) || $ccy === '') {
      return $current;
    }
    $dir = strtolower(trim((string) ($rule['fixed_direction'] ?? 'add')));
    $amount = $dir === 'subtract' ? (string) (-1 * (float) $num) : (string) $num;
    try {
      $delta = new Price($amount, $ccy);
    }
    catch (\Throwable) {
      return $current;
    }
    $base = $variation->getPrice();
    if ($base instanceof Price && $delta->getCurrencyCode() !== $base->getCurrencyCode()) {
      $logger('Court booking: skipping fixed_amount rule (currency @d != base @b).', [
        '@d' => $delta->getCurrencyCode(),
        '@b' => $base->getCurrencyCode(),
      ]);

      return $current;
    }

    try {
      return $current->add($delta);
    }
    catch (CurrencyMismatchException $e) {
      $logger('Court booking: fixed_amount currency mismatch: @m', ['@m' => $e->getMessage()]);

      return $current;
    }
    catch (\Throwable) {
      return $current;
    }
  }

  private static function applySurchargeFieldModifier(
    Price $current,
    ProductVariationInterface $variation,
    array $rule,
    callable $logger,
  ): ?Price {
    $src = strtolower(trim((string) ($rule['surcharge_field'] ?? $rule['surcharge_source'] ?? 'none')));
    if ($src === 'none' || $src === '') {
      return $current;
    }
    $field = match ($src) {
      'peak' => CourtBookingPriceResolver::FIELD_PEAK,
      'weekend' => CourtBookingPriceResolver::FIELD_WEEKEND,
      default => NULL,
    };
    if ($field === NULL || !$variation->hasField($field) || $variation->get($field)->isEmpty()) {
      return $current;
    }
    $value = $variation->get($field)->first()?->getValue();
    if (!is_array($value) || !isset($value['number'], $value['currency_code'])) {
      return $current;
    }
    $currency = strtoupper(trim((string) $value['currency_code']));
    $base = $variation->getPrice();
    if ($base instanceof Price && $currency !== '' && $currency !== $base->getCurrencyCode()) {
      $logger('Court booking: ignoring surcharge_field rule (currency @s != base @b).', [
        '@s' => $currency,
        '@b' => $base->getCurrencyCode(),
      ]);

      return $current;
    }
    try {
      $delta = new Price((string) $value['number'], $currency !== '' ? $currency : $base->getCurrencyCode());
    }
    catch (\Throwable) {
      return $current;
    }

    try {
      return $current->add($delta);
    }
    catch (CurrencyMismatchException $e) {
      $logger('Court booking: surcharge add failed: @m', ['@m' => $e->getMessage()]);

      return $current;
    }
  }

}
