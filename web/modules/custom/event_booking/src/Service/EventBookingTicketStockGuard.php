<?php

namespace Drupal\event_booking\Service;

use Drupal\commerce\Context;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\commerce_stock\StockServiceManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards event ticket add-to-cart requests against stock constraints.
 */
class EventBookingTicketStockGuard extends EventBookingBaseService {

  public function __construct(
    protected StockServiceManagerInterface $stockServiceManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * @return array{status: int, data: array}|null
   */
  public function validate(ProductVariationInterface $variation, StoreInterface $store, AccountInterface $account, OrderInterface $cart, int $requested_quantity): ?array {
    $available_total = $this->resolveAvailableStockTotal($variation, $store, $account);
    if ($available_total === NULL) {
      return NULL;
    }
    $in_cart = $this->countVariationQuantityInCart($cart, (int) $variation->id());
    $remaining = max(0.0, (float) $available_total - $in_cart);
    return $requested_quantity <= $remaining + 1e-6
      ? NULL
      : $this->buildInsufficientStockResponse($available_total, $in_cart, $requested_quantity, $remaining);
  }

  private function resolveAvailableStockTotal(ProductVariationInterface $variation, StoreInterface $store, AccountInterface $account): ?int {
    try {
      $service = $this->stockServiceManager->getService($variation);
    }
    catch (\Throwable $e) {
      $this->logger->warning('event_booking stock: could not resolve stock service for variation @vid: @msg', [
        '@vid' => (string) $variation->id(),
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
    if ($this->isStockServiceAlwaysInStock($service, $variation)) {
      return NULL;
    }
    return $this->readStockLevel($service, $variation, $store, $account);
  }

  private function readStockLevel(object $service, ProductVariationInterface $variation, StoreInterface $store, AccountInterface $account): ?int {
    try {
      $context = new Context($account, $store);
      $locations = $service->getConfiguration()->getAvailabilityLocations($context, $variation);
      $level = $service->getStockChecker()->getTotalAvailableStockLevel($variation, $locations);
    }
    catch (\Throwable $e) {
      $this->logger->error('event_booking stock read failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
    return is_numeric($level) ? max(0, (int) $level) : NULL;
  }

  private function isStockServiceAlwaysInStock(object $service, ProductVariationInterface $variation): bool {
    if ($service->getId() === 'always_in_stock') {
      return TRUE;
    }
    try {
      return (bool) $service->getStockChecker()->getIsAlwaysInStock($variation);
    }
    catch (\Throwable $e) {
      $this->logger->notice('event_booking stock: always-in-stock check skipped: @msg', ['@msg' => $e->getMessage()]);
      return FALSE;
    }
  }

  private function countVariationQuantityInCart(OrderInterface $order, int $variation_id): float {
    $sum = 0.0;
    foreach ($order->getItems() as $item) {
      $purchased = $item->getPurchasedEntity();
      if ($purchased instanceof ProductVariationInterface && (int) $purchased->id() === $variation_id) {
        $sum += (float) $item->getQuantity();
      }
    }
    return $sum;
  }

  private function buildInsufficientStockResponse(int $available_total, float $in_cart, int $requested_quantity, float $remaining): array {
    $payload = [
      'error' => 'insufficient_stock',
      'available_quantity' => $available_total,
      'in_cart_quantity' => $in_cart,
      'requested_quantity' => $requested_quantity,
      'remaining_quantity' => $remaining,
    ];
    $payload['message'] = $this->stockMessage($available_total, $in_cart, $remaining);
    return ['status' => 409, 'data' => $payload];
  }

  private function stockMessage(int $available_total, float $in_cart, float $remaining): string {
    if ($available_total <= 0 || $remaining <= 0) {
      return (string) $this->t('This ticket is sold out or no longer available in stock.');
    }
    return (string) $this->t('Only @count more ticket(s) can be added (@available in stock, @in_cart already in your cart for this ticket).', [
      '@count' => $this->formatQuantityForMessage($remaining),
      '@available' => (string) $available_total,
      '@in_cart' => $this->formatQuantityForMessage($in_cart),
    ]);
  }

  private function formatQuantityForMessage(float $quantity): string {
    if (abs($quantity - round($quantity)) < 1e-6) {
      return (string) (int) round($quantity);
    }
    return rtrim(rtrim(sprintf('%.4f', $quantity), '0'), '.') ?: '0';
  }

}
