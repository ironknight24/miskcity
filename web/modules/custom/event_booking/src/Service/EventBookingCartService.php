<?php

namespace Drupal\event_booking\Service;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Cart, pricing, store, and stock workflows for event booking APIs.
 */
class EventBookingCartService extends EventBookingBaseService
{

  public function __construct(
    protected CartManagerInterface $cartManager,
    protected CartProviderInterface $cartProvider,
    protected OrderRefreshInterface $orderRefresh,
    protected ConfigFactoryInterface $configFactory,
    protected EventBookingStoreResolver $storeResolver,
    protected EventBookingTicketStockGuard $stockGuard,
    protected EventBookingCartSerializer $cartSerializer,
    protected EventBookingCartClearer $cartClearer,
    protected EventBookingTicketPricingService $pricingService,
    protected LoggerInterface $logger,
  ) {}

  public function addTickets(AccountInterface $account, array $data): array
  {
    $response = NULL;

    $settings = $this->configFactory->get('event_booking.settings');
    $store = $this->storeResolver->loadEventStore();

    if (!$store instanceof StoreInterface) {
      $response = [
        'status' => 500,
        'data' => [
          'message' => (string) $this->t('Event store is not configured or invalid.')
        ]
      ];
    } else {
      $input = $this->resolveAddTicketInput($settings, $data);
      $input_error = $this->validateAddTicketInput($settings, $input);

      if ($input_error !== NULL) {
        $response = $input_error;
      } else {
        $variation = ProductVariation::load($input['variation_id']);
        $variation_error = $this->validateVariation($variation, $store);

        if ($variation_error !== NULL) {
          $response = $variation_error;
        } else {
          $response = $this->addVariationToCart(
            $account,
            $store,
            (string) ($settings->get('order_type_id') ?: 'default'),
            $variation,
            $input
          );
        }
      }
    }

    return $response;
  }

  public function getCartPayload(AccountInterface $account): array
  {
    $cart = $this->loadActiveCart($account);
    if (is_array($cart)) {
      return $cart;
    }
    $this->orderRefresh->refresh($cart);
    return ['status' => 200, 'data' => $this->cartSerializer->serializeCart($cart)];
  }

  public function clearCart(AccountInterface $account): array
  {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    $cart = $this->loadActiveCart($account);
    if (is_array($cart)) {
      return $cart;
    }
    return $this->cartClearer->clearDraftCart($cart, $account);
  }

  public function getTicketVariationPricing(AccountInterface $account, ProductVariationInterface $variation): array
  {
    return $this->pricingService->getTicketVariationPricing($account, $variation);
  }

  /**
   * @return array{variation_id:int,quantity:int,max_quantity:int}
   */
  private function resolveAddTicketInput($settings, array $data): array
  {
    return [
      'variation_id' => (int) ($data['variation_id'] ?? $settings->get('default_variation_id') ?? 0),
      'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
      'max_quantity' => max(1, (int) ($settings->get('max_quantity_per_request') ?? 500)),
    ];
  }

  private function validateAddTicketInput($settings, array $input): ?array
  {
    $error = NULL;

    if ($input['quantity'] > $input['max_quantity']) {
      $error = [
        'status' => 400,
        'data' => [
          'message' => (string) $this->t(
            'Quantity exceeds the maximum allowed per request (@max).',
            ['@max' => $input['max_quantity']]
          )
        ]
      ];
    } elseif ($input['variation_id'] <= 0) {
      $error = [
        'status' => 400,
        'data' => [
          'message' => (string) $this->t('Missing or invalid variation_id.')
        ]
      ];
    } else {
      $allowed = array_filter(array_map('intval', (array) $settings->get('allowed_variation_ids')));

      if ($allowed && !in_array($input['variation_id'], $allowed, TRUE)) {
        $error = [
          'status' => 403,
          'data' => [
            'message' => (string) $this->t('This ticket variation is not allowed for API booking.')
          ]
        ];
      }
    }

    return $error;
  }

  private function validateVariation($variation, StoreInterface $store): ?array
  {
    if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid or unavailable ticket variation.')]];
    }
    if (!$this->storeResolver->variationBelongsToStore($variation, $store)) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('This ticket is not sold in the configured event store.')]];
    }
    return NULL;
  }

  private function addVariationToCart(AccountInterface $account, StoreInterface $store, string $order_type, ProductVariationInterface $variation, array $input): array
  {
    $cart = $this->cartProvider->getCart($order_type, $store, $account)
      ?? $this->cartProvider->createCart($order_type, $store, $account);
    $stock_error = $this->stockGuard->validate($variation, $store, $account, $cart, $input['quantity']);
    if ($stock_error !== NULL) {
      return $stock_error;
    }
    return $this->persistCartItem($account, $store, $order_type, $cart, $variation, $input);
  }

  private function persistCartItem(AccountInterface $account, StoreInterface $store, string $order_type, OrderInterface $cart, ProductVariationInterface $variation, array $input): array
  {
    $order_item = $this->cartManager->createOrderItem($variation, (string) $input['quantity']);
    try {
      $this->cartManager->addOrderItem($cart, $order_item, FALSE, TRUE);
    } catch (\Throwable $e) {
      $this->logger->error('event_booking add to cart failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not add tickets to cart.')]];
    }
    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if (!$cart instanceof OrderInterface) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not reload cart.')]];
    }
    $this->orderRefresh->refresh($cart);
    return $this->buildAddTicketsResponse($cart, $order_item, $input);
  }

  private function buildAddTicketsResponse(OrderInterface $cart, object $order_item, array $input): array
  {
    return ['status' => 200, 'data' => [
      'status' => 'ok',
      'order_id' => (int) $cart->id(),
      'order_item_id' => (int) $order_item->id(),
      'variation_id' => $input['variation_id'],
      'quantity' => $input['quantity'],
      'total' => $cart->getTotalPrice() ? $cart->getTotalPrice()->__toString() : NULL,
      'checkout_url' => Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $cart->id(),
        'step' => 'order_information',
      ], ['absolute' => TRUE])->toString(),
    ]];
  }

  /**
   * @return \Drupal\commerce_order\Entity\OrderInterface|array{status:int,data:array}
   */
  private function loadActiveCart(AccountInterface $account): OrderInterface|array
  {
    $store = $this->storeResolver->loadEventStore();
    if (!$store instanceof StoreInterface) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Event store is not configured or invalid.')]];
    }
    $order_type = $this->configFactory->get('event_booking.settings')->get('order_type_id') ?: 'default';
    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    return $cart instanceof OrderInterface ? $cart : ['status' => 404, 'data' => ['message' => (string) $this->t('No active event cart.')]];
  }
}
