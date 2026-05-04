<?php

namespace Drupal\event_booking\Service;

use Drupal\node\NodeInterface;

/**
 * Matches event nodes against API search text.
 */
class EventBookingSearchMatcher {

  /**
   * @param array<string, mixed> $serialized
   */
  public function matchesEventSearch(NodeInterface $node, array $serialized, string $q, string $location_field): bool {
    if ($q === '') {
      return TRUE;
    }
    foreach ($this->buildEventSearchHaystacks($node, $serialized, $location_field) as $text) {
      if ($text !== '' && str_contains($text, $q)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array<string, mixed> $serialized
   *
   * @return string[]
   */
  private function buildEventSearchHaystacks(NodeInterface $node, array $serialized, string $location_field): array {
    $haystacks = [
      mb_strtolower($node->getTitle()),
      isset($serialized['location']) ? mb_strtolower((string) $serialized['location']) : '',
    ];
    if ($node->hasField('field_event_categories') && !$node->get('field_event_categories')->isEmpty()) {
      $haystacks[] = mb_strtolower((string) $node->get('field_event_categories')->value);
    }
    if ($location_field !== 'field_event_categories'
      && $node->hasField($location_field)
      && !$node->get($location_field)->isEmpty()) {
      $haystacks[] = mb_strtolower((string) $node->get($location_field)->value);
    }
    return $haystacks;
  }

}
