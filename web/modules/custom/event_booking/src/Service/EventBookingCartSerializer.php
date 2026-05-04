<?php

namespace Drupal\event_booking\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Url;

/**
 * Serializes Commerce carts for event booking API responses.
 */
class EventBookingCartSerializer {

  /**
   * @return array<string, mixed>
   */
  public function serializeCart(OrderInterface $order): array {
    $checkout_step = $order->get('checkout_step')->value;
    return [
      'order_id' => (int) $order->id(),
      'state' => $order->getState()->getId(),
      'checkout_step' => $checkout_step,
      'total' => $order->getTotalPrice() ? $order->getTotalPrice()->__toString() : NULL,
      'balance' => $order->getBalance() ? $order->getBalance()->__toString() : NULL,
      'line_items' => $this->serializeItems($order),
      'checkout_url' => Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $order->id(),
        'step' => $checkout_step ?: 'order_information',
      ], ['absolute' => TRUE])->toString(),
    ];
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function serializeItems(OrderInterface $order): array {
    $items = [];
    foreach ($order->getItems() as $item) {
      $items[] = [
        'order_item_id' => (int) $item->id(),
        'title' => $item->getTitle(),
        'quantity' => (string) $item->getQuantity(),
      ];
    }
    return $items;
  }

}
