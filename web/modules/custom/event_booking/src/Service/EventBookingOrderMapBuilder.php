<?php

namespace Drupal\event_booking\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Builds completed order maps for booked event lookup.
 */
class EventBookingOrderMapBuilder {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * @return array{variation_ids: int[], variation_orders: array<int, int[]>}
   */
  public function loadCompletedBookedVariationOrderMap(int $uid): array {
    if ($uid <= 0) {
      return ['variation_ids' => [], 'variation_orders' => []];
    }
    $order_ids = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->condition('state', 'completed')
      ->execute();
    return $order_ids ? $this->buildMapFromOrderIds(array_values($order_ids)) : ['variation_ids' => [], 'variation_orders' => []];
  }

  /**
   * @param int[] $order_ids
   * @return array{variation_ids: int[], variation_orders: array<int, int[]>}
   */
  private function buildMapFromOrderIds(array $order_ids): array {
    $orders = $this->entityTypeManager->getStorage('commerce_order')->loadMultiple($order_ids);
    $variation_orders = $this->buildVariationOrdersFromOrders($orders);
    foreach ($variation_orders as &$ids) {
      rsort($ids, SORT_NUMERIC);
      $ids = array_values($ids);
    }
    unset($ids);
    $variation_ids = array_values(array_map('intval', array_keys($variation_orders)));
    sort($variation_ids, SORT_NUMERIC);
    return ['variation_ids' => $variation_ids, 'variation_orders' => $variation_orders];
  }

  /**
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $orders
   * @return array<int, array<int, int>>
   */
  private function buildVariationOrdersFromOrders(array $orders): array {
    $variation_orders = [];
    foreach ($orders as $order) {
      if ($order instanceof OrderInterface) {
        $this->addOrderVariations($variation_orders, $order);
      }
    }
    return $variation_orders;
  }

  private function addOrderVariations(array &$variation_orders, OrderInterface $order): void {
    foreach ($order->getItems() as $item) {
      $purchased = $item->getPurchasedEntity();
      if ($purchased instanceof ProductVariationInterface) {
        $variation_id = (int) $purchased->id();
        $order_id = (int) $order->id();
        $variation_orders[$variation_id][$order_id] = $order_id;
      }
    }
  }

}
