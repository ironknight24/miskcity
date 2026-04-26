<?php

namespace Drupal\court_booking;

use Drupal\commerce_bat\Util\DateTimeHelper;
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
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * REST helpers for Commerce cart and checkout (OAuth clients).
 */
final class CommerceCheckoutRestService {

  use StringTranslationTrait;
  private const API_GATEWAY_ID = 'example_payment';
  private const SESSION_COLLECTION = 'court_booking.payment_sessions';

  public function __construct(
    protected CartProviderInterface $cartProvider,
    protected CurrentStoreInterface $currentStore,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
    protected OrderRefreshInterface $orderRefresh,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    protected KeyValueFactoryInterface $keyValueFactory,
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
   *
   * Rental `value` / `end_value` remain stored UTC strings (Commerce BAT).
   * `start` / `end` are the same instants in the effective display timezone
   * (see \Drupal\court_booking\CourtBookingRegional::effectiveTimeZoneId).
   */
  public function buildCartPayload(OrderInterface $order, ?AccountInterface $account = NULL): array {
    $tz_account = $account ?? $order->getCustomer();
    if (!$tz_account instanceof AccountInterface) {
      $tz_account = \Drupal::currentUser();
    }
    $tz_id = CourtBookingRegional::effectiveTimeZoneId($this->configFactory, $tz_account);
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
            'timezone' => $tz_id,
          ];
          $row['rental'] += $this->rentalUtcStringsToDisplayIso(
            (string) ($val['value'] ?? ''),
            (string) ($val['end_value'] ?? ''),
            $tz_id,
            (int) $item->id(),
          );
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
   * Converts stored UTC rental strings to ISO-8601 in the display timezone.
   *
   * @return array{start?: string, end?: string}
   */
  private function rentalUtcStringsToDisplayIso(string $value, string $end_value, string $tz_id, int $order_item_id): array {
    $out = [];
    try {
      $tz = new \DateTimeZone($tz_id);
    }
    catch (\Throwable $e) {
      $this->logger->warning('court_booking cart rental: invalid timezone @tz for order_item=@id: @msg', [
        '@tz' => $tz_id,
        '@id' => (string) $order_item_id,
        '@msg' => $e->getMessage(),
      ]);
      return $out;
    }
    foreach ([['raw' => $value, 'key' => 'start'], ['raw' => $end_value, 'key' => 'end']] as $spec) {
      $raw = trim($spec['raw']);
      if ($raw === '') {
        continue;
      }
      try {
        $utc = DateTimeHelper::normalizeUtc(DateTimeHelper::parseUtc($raw, FALSE));
        $out[$spec['key']] = $utc->setTimezone($tz)->format(DATE_ATOM);
      }
      catch (\Throwable $e) {
        $this->logger->warning('court_booking cart rental: could not format @key for order_item=@id: @msg', [
          '@key' => $spec['key'],
          '@id' => (string) $order_item_id,
          '@msg' => $e->getMessage(),
        ]);
      }
    }
    return $out;
  }

  /**
   * Collects payment details for API-first offsite payment confirmation.
   *
   * Stores only masked/non-sensitive metadata in key-value storage.
   *
   * @return array{status: int, data: array}
   */
  public function collectPaymentDetails(OrderInterface $order, AccountInterface $account, array $data): array {
    $validation = $this->reloadOwnedDraftOrder($order, $account);
    if ($validation['status'] !== 200) {
      return $validation;
    }
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $validation['order'];

    $gateway_id = trim((string) ($data['payment_gateway_id'] ?? self::API_GATEWAY_ID));
    if ($gateway_id !== self::API_GATEWAY_ID) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('This API flow currently supports only the example_payment gateway.')]];
    }
    $gateway = $this->loadPaymentGateway($gateway_id);
    if (!$gateway instanceof PaymentGatewayInterface || !$gateway->getPlugin() instanceof OffsitePaymentGatewayInterface) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Configured gateway is not an offsite API-compatible gateway.')]];
    }

    $payment = $data['payment'] ?? NULL;
    if (!is_array($payment)) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing payment payload.')]];
    }
    $card_number = preg_replace('/\D+/', '', (string) ($payment['card_number'] ?? ''));
    $cvv = preg_replace('/\D+/', '', (string) ($payment['cvv'] ?? ''));
    $holder = trim((string) ($payment['card_holder_name'] ?? ''));
    $brand = trim((string) ($payment['card_brand'] ?? ''));
    $exp_month = (int) ($payment['exp_month'] ?? 0);
    $exp_year = (int) ($payment['exp_year'] ?? 0);
    $billing_email = trim((string) ($payment['billing_email'] ?? ''));
    if (strlen($card_number) < 12 || strlen($card_number) > 19 || strlen($cvv) < 3 || $holder === '' || $exp_month < 1 || $exp_month > 12 || $exp_year < 2024 || $billing_email === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid payment payload. Provide valid card_holder_name, card_number, cvv, exp_month, exp_year, and billing_email.')]];
    }

    try {
      $session_id = 'pay_' . bin2hex(random_bytes(12));
    }
    catch (\Throwable) {
      $session_id = 'pay_' . md5((string) (microtime(TRUE) . $order->id() . $account->id()));
    }
    $store = $this->keyValueFactory->get(self::SESSION_COLLECTION);
    $store->set($session_id, [
      'order_id' => (int) $order->id(),
      'uid' => (int) $account->id(),
      'gateway_id' => $gateway_id,
      'status' => 'details_collected',
      'card_last4' => substr($card_number, -4),
      'card_brand' => $brand,
      'card_holder_name' => $holder,
      'billing_email' => mb_strtolower($billing_email),
      'created_at' => time(),
      'updated_at' => time(),
    ]);

    $order->setData('court_booking_api_payment', [
      'session_id' => $session_id,
      'gateway_id' => $gateway_id,
      'status' => 'details_collected',
      'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
    ]);
    $order->save();

    return ['status' => 200, 'data' => [
      'status' => 'details_collected',
      'order_id' => (int) $order->id(),
      'payment_session_id' => $session_id,
      'gateway' => $gateway_id,
      'next_action' => 'confirm_payment',
    ]];
  }

  /**
   * Confirms an offsite API payment and places the draft order.
   *
   * @return array{status: int, data: array}
   */
  public function confirmPayment(OrderInterface $order, AccountInterface $account, array $data): array {
    $validation = $this->reloadOwnedDraftOrder($order, $account);
    if ($validation['status'] !== 200) {
      return $validation;
    }
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $validation['order'];

    $session_id = trim((string) ($data['payment_session_id'] ?? ''));
    if ($session_id === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing payment_session_id.')]];
    }
    $store = $this->keyValueFactory->get(self::SESSION_COLLECTION);
    $session = $store->get($session_id);
    if (!is_array($session)) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('Payment session not found.')]];
    }
    if ((int) ($session['order_id'] ?? 0) !== (int) $order->id() || (int) ($session['uid'] ?? 0) !== (int) $account->id()) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('Payment session does not belong to this order/user.')]];
    }

    if (!empty($session['payment_id'])) {
      $storage = $this->entityTypeManager->getStorage('commerce_order');
      $placed = $storage->load($order->id());
      return ['status' => 200, 'data' => [
        'order_id' => (int) $order->id(),
        'state' => $placed instanceof OrderInterface ? $placed->getState()->getId() : $order->getState()->getId(),
        'payment_id' => (int) $session['payment_id'],
        'message' => (string) $this->t('Order already completed.'),
      ]];
    }

    $confirm_status = strtolower(trim((string) ($data['payment_status'] ?? '')));
    if (!in_array($confirm_status, ['authorized', 'captured', 'paid', 'success'], TRUE)) {
      return ['status' => 409, 'data' => [
        'message' => (string) $this->t('Payment is not in a successful status.'),
        'payment_status' => $confirm_status,
      ]];
    }
    $gateway = $this->loadPaymentGateway((string) ($session['gateway_id'] ?? self::API_GATEWAY_ID));
    if (!$gateway instanceof PaymentGatewayInterface) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Payment gateway is unavailable.')]];
    }
    $reference = trim((string) ($data['gateway_reference'] ?? ''));
    if ($reference === '') {
      $reference = $session_id;
    }

    $payment_id = $this->createCompletedPayment($order, $gateway, $reference);
    $this->finalizePlacedOrder($order);

    $session['status'] = 'completed';
    $session['payment_status'] = $confirm_status;
    $session['payment_id'] = $payment_id;
    $session['gateway_reference'] = $reference;
    $session['updated_at'] = time();
    $store->set($session_id, $session);

    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $placed = $storage->load($order->id());
    return ['status' => 200, 'data' => [
      'order_id' => (int) $order->id(),
      'state' => $placed instanceof OrderInterface ? $placed->getState()->getId() : $order->getState()->getId(),
      'payment_id' => $payment_id,
      'message' => (string) $this->t('Order completed.'),
    ]];
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
    $validation = $this->reloadOwnedDraftOrder($order, $account);
    if ($validation['status'] !== 200) {
      return $validation;
    }
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $validation['order'];
    $storage = $this->entityTypeManager->getStorage('commerce_order');

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
    $gateway = $this->loadPaymentGateway($gateway_id);
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

  /**
   * Records a payment failure reported by authenticated client.
   *
   * @return array{status: int, data: array}
   */
  public function reportPaymentFailure(OrderInterface $order, AccountInterface $account, array $data): array {
    if ((int) $order->getCustomerId() !== (int) $account->id()) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('Access denied.')]];
    }

    $gateway = trim((string) ($data['gateway'] ?? ''));
    $code = trim((string) ($data['code'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));
    $raw = $data['raw'] ?? NULL;
    if ($gateway === '' && $code === '' && $message === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Provide at least one of gateway, code, message.')]];
    }

    $order->setData('court_booking_payment_failure', [
      'source' => 'client',
      'gateway' => $gateway,
      'code' => $code,
      'message' => $message,
      'raw' => $raw,
      'reported_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
      'order_state' => $order->getState()->getId(),
    ]);
    $this->applyCancelTransitionIfPossible($order);
    $order->save();
    $this->logger->warning('Payment failure reported for order @order: @code @message', [
      '@order' => (int) $order->id(),
      '@code' => $code,
      '@message' => $message,
    ]);

    return ['status' => 200, 'data' => [
      'status' => 'recorded',
      'order_id' => (int) $order->id(),
      'state' => $order->getState()->getId(),
      'refund' => 'not_automated',
      'message' => (string) $this->t('Payment failure recorded.'),
    ]];
  }

  /**
   * Processes asynchronous payment status callback.
   *
   * @return array{status: int, data: array}
   */
  public function processPaymentWebhook(Request $request, array $data): array {
    $secret = (string) Settings::get('court_booking_webhook_secret', '');
    if ($secret === '') {
      return ['status' => 503, 'data' => ['message' => (string) $this->t('Webhook secret is not configured.')]];
    }
    if (!$this->isWebhookSignatureValid($request, $secret)) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Invalid webhook signature.')]];
    }

    $event_id = trim((string) ($data['event_id'] ?? ''));
    $order_id = (int) ($data['order_id'] ?? 0);
    $status = strtolower(trim((string) ($data['status'] ?? '')));
    if ($event_id === '' || $order_id <= 0 || $status === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing required fields: event_id, order_id, status.')]];
    }

    $store = $this->keyValueFactory->get('court_booking.payment_webhooks');
    if ($store->has($event_id)) {
      return ['status' => 200, 'data' => [
        'status' => 'duplicate',
        'event_id' => $event_id,
      ]];
    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    if (!$order instanceof OrderInterface) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('Order not found.')]];
    }

    $is_failure = in_array($status, ['failed', 'declined', 'expired'], TRUE);
    $is_success = in_array($status, ['paid', 'success', 'completed'], TRUE);
    $payment_session_id = trim((string) ($data['payment_session_id'] ?? $data['session_id'] ?? ''));
    $order->setData('court_booking_payment_webhook', [
      'event_id' => $event_id,
      'status' => $status,
      'payment_session_id' => $payment_session_id,
      'gateway' => trim((string) ($data['gateway'] ?? '')),
      'code' => trim((string) ($data['code'] ?? '')),
      'message' => trim((string) ($data['message'] ?? '')),
      'raw' => $data['raw'] ?? NULL,
      'received_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
    ]);
    if ($is_failure) {
      $this->applyCancelTransitionIfPossible($order);
    }
    if ($is_success && $payment_session_id !== '') {
      $this->applyWebhookSuccessCompletion($order, $payment_session_id, $data);
      $reloaded_order = $this->entityTypeManager->getStorage('commerce_order')->load($order->id());
      if ($reloaded_order instanceof OrderInterface) {
        $order = $reloaded_order;
      }
    }
    $order->save();
    $store->set($event_id, [
      'order_id' => $order_id,
      'status' => $status,
      'processed_at' => time(),
    ]);

    return ['status' => 200, 'data' => [
      'status' => $is_failure ? 'failure_recorded' : ($is_success ? 'success_recorded' : 'recorded'),
      'order_id' => (int) $order->id(),
      'state' => $order->getState()->getId(),
      'event_id' => $event_id,
    ]];
  }

  /**
   * Applies cancel transition only when available for the current order state.
   */
  private function applyCancelTransitionIfPossible(OrderInterface $order): void {
    try {
      if ($order->getState()->getId() !== 'canceled') {
        $order->getState()->applyTransitionById('cancel');
      }
    }
    catch (\Throwable $e) {
      // Non-fatal: keep audit data even if transition is unavailable.
      $this->logger->warning('Unable to apply cancel transition on order @order: @msg', [
        '@order' => (int) $order->id(),
        '@msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Verifies webhook signature using HMAC SHA-256.
   */
  private function isWebhookSignatureValid(Request $request, string $secret): bool {
    $signature = trim((string) $request->headers->get('X-Payment-Signature', ''));
    $timestamp = trim((string) $request->headers->get('X-Payment-Timestamp', ''));
    if ($signature === '' || $timestamp === '' || !ctype_digit($timestamp)) {
      return FALSE;
    }
    $timestamp_i = (int) $timestamp;
    if (abs(time() - $timestamp_i) > 300) {
      return FALSE;
    }
    $payload = $timestamp . '.' . $request->getContent();
    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
  }

  /**
   * Reloads and validates that the order belongs to the account and is draft.
   *
   * @return array{status: int, data?: array, order?: \Drupal\commerce_order\Entity\OrderInterface}
   */
  private function reloadOwnedDraftOrder(OrderInterface $order, AccountInterface $account): array {
    if ((int) $order->getCustomerId() !== (int) $account->id()) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('Access denied.')]];
    }
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    /** @var \Drupal\commerce_order\Entity\OrderInterface|null $fresh */
    $fresh = $storage->load($order->id());
    if (!$fresh instanceof OrderInterface) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('Order not found.')]];
    }
    $this->orderRefresh->refresh($fresh);
    if ($fresh->getState()->getId() !== 'draft') {
      return ['status' => 400, 'data' => [
        'message' => (string) $this->t('This order is not waiting for checkout.'),
        'state' => $fresh->getState()->getId(),
      ]];
    }
    return ['status' => 200, 'order' => $fresh];
  }

  private function loadPaymentGateway(string $gateway_id): ?PaymentGatewayInterface {
    $gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id);
    return $gateway instanceof PaymentGatewayInterface ? $gateway : NULL;
  }

  /**
   * Creates a completed payment, idempotent by remote reference when provided.
   */
  private function createCompletedPayment(OrderInterface $order, PaymentGatewayInterface $gateway, string $remote_reference): int {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    if ($remote_reference !== '') {
      $existing = $payment_storage->loadByProperties([
        'order_id' => $order->id(),
        'remote_id' => $remote_reference,
      ]);
      $existing_payment = reset($existing);
      if ($existing_payment) {
        return (int) $existing_payment->id();
      }
    }

    $order->set('payment_gateway', $gateway);
    $order->save();

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $payment_storage->create([
      'state' => 'completed',
      'amount' => $order->getBalance(),
      'payment_gateway' => $gateway->id(),
      'order_id' => $order->id(),
      'remote_id' => $remote_reference ?: NULL,
    ]);
    $payment->save();
    return (int) $payment->id();
  }

  /**
   * Completes a tracked API session when webhook reports success.
   */
  private function applyWebhookSuccessCompletion(OrderInterface $order, string $session_id, array $data): void {
    $store = $this->keyValueFactory->get(self::SESSION_COLLECTION);
    $session = $store->get($session_id);
    if (!is_array($session) || (int) ($session['order_id'] ?? 0) !== (int) $order->id()) {
      return;
    }
    if (!empty($session['payment_id'])) {
      return;
    }
    $gateway_id = (string) ($session['gateway_id'] ?? self::API_GATEWAY_ID);
    $gateway = $this->loadPaymentGateway($gateway_id);
    if (!$gateway instanceof PaymentGatewayInterface) {
      return;
    }
    $remote_reference = trim((string) ($data['gateway_reference'] ?? $data['event_id'] ?? $session_id));
    $payment_id = $this->createCompletedPayment($order, $gateway, $remote_reference);
    $this->finalizePlacedOrder($order);

    $session['status'] = 'completed';
    $session['payment_status'] = strtolower(trim((string) ($data['status'] ?? 'success')));
    $session['gateway_reference'] = $remote_reference;
    $session['payment_id'] = $payment_id;
    $session['updated_at'] = time();
    $store->set($session_id, $session);
  }

}
