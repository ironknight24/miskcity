<?php

namespace Drupal\event_booking\Service;

use Drupal\node\NodeInterface;

/**
 * Serializes event nodes and event date/search data for API responses.
 */
class EventBookingNodeSerializer extends EventBookingBaseService {

  public function __construct(
    protected EventBookingDateFormatter $dateFormatter,
    protected EventBookingImageBuilder $imageBuilder,
    protected EventBookingFieldSerializer $fieldSerializer,
    protected EventBookingSearchMatcher $searchMatcher,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function serializeEventNode(NodeInterface $node, string $date_field, string $image_field, string $location_field): array {
    $out = [
      'nid' => (int) $node->id(),
      'title' => $node->getTitle(),
    ];
    if ($node->hasField($date_field) && !$node->get($date_field)->isEmpty()) {
      $dr = $node->get($date_field)->first();
      if ($dr) {
        $val = $dr->getValue();
        $out['event_schedule'] = [
          'start' => isset($val['value']) ? $this->dateFormatter->formatUtcStringToSiteIso((string) $val['value']) : NULL,
          'end' => isset($val['end_value']) ? $this->dateFormatter->formatUtcStringToSiteIso((string) $val['end_value']) : NULL,
        ];
      }
    }
    if ($node->hasField($location_field) && !$node->get($location_field)->isEmpty()) {
      $out['location'] = $node->get($location_field)->value;
    }
    $out['image'] = $this->imageBuilder->buildImageData($node, $image_field);
    $out['fields'] = $this->fieldSerializer->serializeNodeFields($node);
    return $out;
  }

  /**
   * @return array{0:int|null,1:int|null}
   */
  public function extractEventTimestamps(NodeInterface $node, string $date_field): array {
    return $this->dateFormatter->extractEventTimestamps($node, $date_field);
  }

  public function toTimestamp(string $value): ?int {
    return $this->dateFormatter->toTimestamp($value);
  }

  /**
   * @param array<string, mixed> $serialized
   */
  public function matchesEventSearch(NodeInterface $node, array $serialized, string $q, string $location_field): bool {
    return $this->searchMatcher->matchesEventSearch($node, $serialized, $q, $location_field);
  }

}
