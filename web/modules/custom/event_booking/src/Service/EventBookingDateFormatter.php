<?php

namespace Drupal\event_booking\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\NodeInterface;

/**
 * Formats and parses event date values for API responses.
 */
class EventBookingDateFormatter {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array{0:int|null,1:int|null}
   */
  public function extractEventTimestamps(NodeInterface $node, string $date_field): array {
    if (!$node->hasField($date_field) || $node->get($date_field)->isEmpty()) {
      return [NULL, NULL];
    }
    $item = $node->get($date_field)->first();
    return $item ? $this->timestampsFromValue($item->getValue()) : [NULL, NULL];
  }

  public function toTimestamp(string $value): ?int {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    try {
      return (new DrupalDateTime($value, 'UTC'))->getTimestamp();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @param array<string, mixed> $value
   * @return array<string, mixed>
   */
  public function normalizeFieldDateValuesToSiteTimezone(array $value, string $field_type): array {
    if (!in_array($field_type, ['datetime', 'daterange'], TRUE)) {
      return $value;
    }
    foreach (['value', 'end_value'] as $key) {
      if (isset($value[$key]) && is_string($value[$key]) && $value[$key] !== '') {
        $value[$key] = $this->formatUtcStringToSiteIso($value[$key]) ?? $value[$key];
      }
    }
    return $value;
  }

  public function formatUtcStringToSiteIso(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    try {
      $utc = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
      return $utc->setTimezone(new \DateTimeZone($this->siteTimezoneId()))->format(DATE_ATOM);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @param array<string, mixed> $value
   * @return array{0:int|null,1:int|null}
   */
  private function timestampsFromValue(array $value): array {
    return [
      isset($value['value']) ? $this->toTimestamp((string) $value['value']) : NULL,
      isset($value['end_value']) ? $this->toTimestamp((string) $value['end_value']) : NULL,
    ];
  }

  private function siteTimezoneId(): string {
    $tz = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?? 'UTC');
    return $tz !== '' ? $tz : 'UTC';
  }

}
