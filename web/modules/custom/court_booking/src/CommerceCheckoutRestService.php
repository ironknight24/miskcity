<?php

namespace Drupal\court_booking;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_checkout\Event\CheckoutEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\ManualPaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * REST helpers for Commerce cart and checkout (OAuth clients).
 */
final class CommerceCheckoutRestService {

  use StringTranslationTrait;

  public function __construct(
    protected CartProviderInterface $cartProvider,
    protected CurrentStoreInterface $currentStore,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
    protected OrderRefreshInterface $orderRefresh,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Loads the active cart for court booking order type when possible.
   */
  public function getCartOrder(AccountInterface $account, ?string $order_type_id = NULL): ?OrderInterface {
    $store = $this->currentStore->getStore();
    if (!$store) {
      return NULL;
    }
    if ($order_type_id === NULL) {
      $order_type_id = $this->configFactory->get('court_booking.settings')->get('order_type_id') ?: 'default';
    }
    $cart = $this->cartProvider->getCart($order_type_id, $store, $account);
    if (!$cart instanceof OrderInterface) {
      return NULL;
    }
    $this->orderRefresh->refresh($cart);
    return $cart;
  }

  /**
   * Serializes cart for JSON.
   */
  public function buildCartPayload(OrderInterface $order): array {
    $items = [];
    foreach ($order->getItems() as $item) {
      $row = [
        'order_item_id' => (int) $item->id(),
        'title' => $item->getTitle(),
        'quantity' => (string) $item->getQuantity(),
      ];
      if ($item->hasField('field_cbat_rental_date') && !$item->get('field_cbat_rental_date')->isEmpty()) {
        $dr = $item->get('field_cbat_rental_date')->first();
        if ($dr && method_exists($dr, 'getValue')) {
          $val = $dr->getValue();
          $row['rental'] = [
            'value' => $val['value'] ?? NULL,
            'end_value' => $val['end_value'] ?? NULL,
          ];
        }
      }
      $items[] = $row;
    }
    $checkout_step = $order->get('checkout_step')->value;
    return [
      'order_id' => (int) $order->id(),
      'state' => $order->getState()->getId(),
      'checkout_step' => $checkout_step,
      'total' => $order->getTotalPrice() ? $order->getTotalPrice()->__toString() : NULL,
      'balance' => $order->getBalance() ? $order->getBalance()->__toString() : NULL,
      'line_items' => $items,
      'checkout_url' => Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $order->id(),
        'step' => $checkout_step ?: 'order_information',
      ], ['absolute' => TRUE])->toString(),
    ];
  }

  /**
   * Completes payment and places order (manual gateway or zero balance).
   *
   * @param array $data
   *   Optional keys: payment_gateway_id, manual_received (bool).
   *
   * @return array{status: int, data: array}
   */
  public function payAndPlaceOrder(OrderInterface $order, AccountInterface $account, array $data): array {
    if ((int) $order->getCustomerId() !== (int) $account->id()) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('Access denied.')]];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $storage->load($order->id());
    $this->orderRefresh->refresh($order);

    if ($order->getState()->getId() !== 'draft') {
      return ['status' => 400, 'data' => [
        'message' => (string) $this->t('This order is not waiting for checkout.'),
        'state' => $order->getState()->getId(),
      ]];
    }

    $balance = $order->getBalance();
    if ($balance->isZero()) {
      $this->finalizePlacedOrder($order);
      $placed = $storage->load($order->id());
      return ['status' => 200, 'data' => [
        'order_id' => (int) $order->id(),
        'state' => $placed instanceof OrderInterface ? $placed->getState()->getId() : $order->getState()->getId(),
        'message' => (string) $this->t('Order completed.'),
      ]];
    }

    $gateway_id = trim((string) ($data['payment_gateway_id'] ?? ''));
    if ($gateway_id === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing payment_gateway_id.')]];
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface|null $gateway */
    $gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id);
    if (!$gateway instanceof PaymentGatewayInterface) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid payment gateway.')]];
    }

    $plugin = $gateway->getPlugin();
    if ($plugin instanceof OffsitePaymentGatewayInterface) {
      return ['status' => 422, 'data' => [
        'message' => (string) $this->t('This payment gateway requires a browser redirect. Use the checkout_url or complete payment in the web checkout flow.'),
        'checkout_url' => Url::fromRoute('commerce_checkout.form', [
          'commerce_order' => $order->id(),
          'step' => 'payment',
        ], ['absolute' => TRUE])->toString(),
      ]];
    }

    if ($plugin instanceof ManualPaymentGatewayInterface) {
      $order->set('payment_gateway', $gateway);
      $order->save();
      $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = $payment_storage->create([
        'state' => 'new',
        'amount' => $order->getBalance(),
        'payment_gateway' => $gateway->id(),
        'order_id' => $order->id(),
      ]);
      $received = array_key_exists('manual_received', $data) ? !empty($data['manual_received']) : TRUE;
      $plugin->createPayment($payment, $received);
      $order = $storage->load($order->id());
      if (!$order instanceof OrderInterface) {
        return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not reload order after payment.')]];
      }
      $this->orderRefresh->refresh($order);
      $this->finalizePlacedOrder($order);
      $placed = $storage->load($order->id());
      return ['status' => 200, 'data' => [
        'order_id' => (int) $order->id(),
        'state' => $placed instanceof OrderInterface ? $placed->getState()->getId() : $order->getState()->getId(),
        'payment_id' => (int) $payment->id(),
        'message' => (string) $this->t('Order completed.'),
      ]];
    }

    return ['status' => 501, 'data' => [
      'message' => (string) $this->t('Automated API payment is only implemented for manual gateways. Use onsite payment method flows or web checkout.'),
      'checkout_url' => Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $order->id(),
        'step' => 'payment',
      ], ['absolute' => TRUE])->toString(),
    ]];
  }

  /**
   * Dispatches checkout completion and applies the place transition.
   */
  protected function finalizePlacedOrder(OrderInterface $order): void {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $fresh = $storage->load($order->id());
    if (!$fresh instanceof OrderInterface) {
      return;
    }
    $fresh->unlock();
    $event = new OrderEvent($fresh);
    $this->eventDispatcher->dispatch($event, CheckoutEvents::COMPLETION);
    if ($fresh->getState()->getId() === 'draft') {
      $fresh->getState()->applyTransitionById('place');
    }
    $fresh->save();
  }

}
