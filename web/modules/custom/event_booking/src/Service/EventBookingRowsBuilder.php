<?php

namespace Drupal\event_booking\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Builds filtered and sortable booked-event rows.
 */
class EventBookingRowsBuilder
{

  public function __construct(
    protected EventBookingNodeSerializer $nodeSerializer,
  ) {}

  /**
   * @param \Drupal\node\NodeInterface[] $nodes
   * @param array<string, string> $field_map
   * @param array<int, int[]> $variation_orders
   *
   * @return array<int, array<string, mixed>>
   */
  public function build(array $nodes, AccountInterface $account, string $bucket, array $field_map, string $variation_field, array $variation_orders, string $q, int $now): array
  {
    $items = [];
    $seen = [];
    foreach ($nodes as $node) {
      $row = $this->buildRow($node, $account, $bucket, $field_map, $variation_field, $variation_orders, $q, $now, $seen);
      if ($row !== NULL) {
        $items[] = $row;
      }
    }
    return $items;
  }

  /**
   * @param array<string, string> $field_map
   * @param array<int, int[]> $variation_orders
   * @param array<int, bool> $seen
   */
  private function buildRow($node, AccountInterface $account, string $bucket, array $field_map, string $variation_field, array $variation_orders, string $q, int $now, array &$seen): ?array
  {
    $result = NULL;

    if ($node instanceof NodeInterface && $this->canUseNode($node, $account, $seen)) {
      $timestamps = $this->extractBucketTimestamps($node, $bucket, $field_map['date'], $now);

      if ($timestamps !== NULL) {
        $order_ids = $this->orderIdsForEventNode($node, $variation_field, $variation_orders);

        if ($order_ids !== []) {
          $result = $this->buildSerializedRow(
            $node,
            $bucket,
            $field_map,
            $order_ids,
            $timestamps,
            $q
          );
        }
      }
    }

    return $result;
  }

  /**
   * @param array<int, bool> $seen
   */
  private function canUseNode(NodeInterface $node, AccountInterface $account, array &$seen): bool
  {
    $nid = (int) $node->id();
    if (isset($seen[$nid]) || !$node->access('view', $account)) {
      return FALSE;
    }
    $seen[$nid] = TRUE;
    return TRUE;
  }

  /**
   * @return array{0: int|null, 1: int}|null
   */
  private function extractBucketTimestamps(NodeInterface $node, string $bucket, string $date_field, int $now): ?array
  {
    [$start_ts, $end_ts] = $this->nodeSerializer->extractEventTimestamps($node, $date_field);
    $effective_end = $end_ts ?? $start_ts;
    if ($effective_end === NULL) {
      return NULL;
    }
    $is_upcoming = $effective_end > $now;
    return (($bucket === 'upcoming' && !$is_upcoming) || ($bucket === 'completed' && $is_upcoming))
      ? NULL
      : [$start_ts, $effective_end];
  }

  /**
   * @param array<int, int[]> $variation_orders
   * @return int[]
   */
  private function orderIdsForEventNode(NodeInterface $node, string $variation_field, array $variation_orders): array
  {
    if (!$node->hasField($variation_field) || $node->get($variation_field)->isEmpty()) {
      return [];
    }
    $order_ids = $this->collectOrderIdsFromVariationItems($node->get($variation_field), $variation_orders);
    rsort($order_ids, SORT_NUMERIC);
    return array_values($order_ids);
  }

  /**
   * @param iterable<object> $field_items
   * @param array<int, int[]> $variation_orders
   *
   * @return array<int, int>
   */
  private function collectOrderIdsFromVariationItems(iterable $field_items, array $variation_orders): array
  {
    $order_ids = [];
    foreach ($field_items as $item) {
      $variation_id = (int) ($item->target_id ?? 0);
      foreach ($variation_orders[$variation_id] ?? [] as $order_id) {
        $order_ids[(int) $order_id] = (int) $order_id;
      }
    }
    return $order_ids;
  }

  /**
   * @param array<string, string> $field_map
   * @param int[] $order_ids
   * @param array{0:int|null,1:int} $timestamps
   */
  private function buildSerializedRow(NodeInterface $node, string $bucket, array $field_map, array $order_ids, array $timestamps, string $q): ?array
  {
    unset($bucket);
    $serialized = $this->nodeSerializer->serializeEventNode($node, $field_map['date'], $field_map['image'], $field_map['location']);
    if (!$this->nodeSerializer->matchesEventSearch($node, $serialized, $q, $field_map['location'])) {
      return NULL;
    }
    [$start_ts, $effective_end] = $timestamps;
    return [
      'nid' => $serialized['nid'] ?? (int) $node->id(),
      'order_id' => $order_ids[0],
      'order_ids' => $order_ids,
      '_sort_start' => $start_ts ?? $effective_end,
      '_sort_end' => $effective_end,
    ] + $serialized;
  }

  /**
   * @param array<int, array<string, mixed>> $items
   * @return array<int, array<string, mixed>>
   */
  public function sortAndStrip(array $items, string $bucket): array
  {
    usort($items, static function (array $a, array $b) use ($bucket): int {
      $a_start = (int) ($a['_sort_start'] ?? 0);
      $b_start = (int) ($b['_sort_start'] ?? 0);
      return $bucket === 'upcoming' ? ($a_start <=> $b_start) : ($b_start <=> $a_start);
    });
    foreach ($items as &$item) {
      unset($item['_sort_start'], $item['_sort_end']);
    }
    unset($item);
    return $items;
  }
}
