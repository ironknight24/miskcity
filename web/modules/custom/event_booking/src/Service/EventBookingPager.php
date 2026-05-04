<?php

namespace Drupal\event_booking\Service;

/**
 * Builds consistent pager payloads for event booking lists.
 */
class EventBookingPager {

  /**
   * @param array<int, array<string, mixed>> $rows
   * @return array{rows: array<int, array<string, mixed>>, pager: array<string, int|string>}
   */
  public function build(array $rows, int $page, int $limit): array {
    $total = count($rows);
    $total_pages = $limit > 0 ? max(1, (int) ceil($total / $limit)) : 1;
    $paged_rows = $limit > 0 ? array_slice($rows, $page * $limit, $limit) : $rows;
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

}
