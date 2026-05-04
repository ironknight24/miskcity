<?php

namespace Drupal\event_booking\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Clears draft event carts.
 */
class EventBookingCartClearer extends EventBookingBaseService {

  public function __construct(
    protected OrderRefreshInterface $orderRefresh,
    protected LoggerInterface $logger,
  ) {}

  public function clearDraftCart(OrderInterface $cart, AccountInterface $account): array {
    if ((int) $cart->getCustomerId() !== (int) $account->id()) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('Access denied.')]];
    }
    $this->orderRefresh->refresh($cart);
    if ($cart->getState()->getId() !== 'draft') {
      return ['status' => 409, 'data' => [
        'message' => (string) $this->t('Only draft carts can be cleared.'),
        'state' => $cart->getState()->getId(),
      ]];
    }
    return $this->removeAllCartItems($cart);
  }

  private function removeAllCartItems(OrderInterface $cart): array {
    $count = 0;
    try {
      foreach ($cart->getItems() as $item) {
        $cart->removeItem($item);
        $item->delete();
        $count++;
      }
      $this->orderRefresh->refresh($cart);
      $cart->save();
      return $this->successResponse($cart, $count);
    }
    catch (\Throwable $e) {
      $this->logger->error('event_booking clear cart failed for order @order: @msg', [
        '@order' => (int) $cart->id(),
        '@msg' => $e->getMessage(),
      ]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not clear event cart.')]];
    }
  }

  private function successResponse(OrderInterface $cart, int $count): array {
    return ['status' => 200, 'data' => [
      'status' => 'ok',
      'message' => (string) $this->t('Cart cleared.'),
      'order_id' => (int) $cart->id(),
      'removed_count' => $count,
      'remaining_items' => count($cart->getItems()),
    ]];
  }

}
