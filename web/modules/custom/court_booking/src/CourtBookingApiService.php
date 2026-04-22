<?php

namespace Drupal\court_booking;

use Drupal\commerce_bat\Availability\AvailabilityManagerInterface;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Shared JSON logic for session-based and OAuth REST court booking endpoints.
 */
final class CourtBookingApiService {

  use StringTranslationTrait;

  public function __construct(
    protected BatUnitEnsurer $batUnitEnsurer,
    protected CartManagerInterface $cartManager,
    protected CartProviderInterface $cartProvider,
    protected CurrentStoreInterface $currentStore,
    protected LoggerInterface $logger,
    protected CourtBookingSlotBooking $slotBooking,
    protected OrderRefreshInterface $orderRefresh,
    protected AvailabilityManagerInterface $availabilityManager,
    protected ConfigFactoryInterface $configFactory,
    protected CheckoutOrderManagerInterface $checkoutOrderManager,
  ) {}

  /**
   * Slot candidates (buffer mode) from decoded JSON body.
   *
   * @param array $data
   *   Same keys as SlotCandidatesController.
   *
   * @return array{status: int, data: array}
   */
  public function buildSlotCandidatesResponse(array $data, AccountInterface $account): array {
    $ymd = trim((string) ($data['ymd'] ?? ''));
    $play_minutes = (int) ($data['duration_minutes'] ?? 0);
    if ($play_minutes <= 0) {
      $play_minutes = max(1, min(24, (int) ($data['duration_hours'] ?? 1))) * 60;
    }
    $play_minutes = max(1, min(24 * 60, $play_minutes));
    $variation_raw = $data['variation_ids'] ?? [];
    if (!is_array($variation_raw)) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('variation_ids must be an array.')]];
    }
    $variation_ids = array_values(array_unique(array_filter(array_map('intval', $variation_raw))));
    $quantity = max(1, (int) ($data['quantity'] ?? 1));

    if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid date.')]];
    }
    if (!$variation_ids) {
      return ['status' => 200, 'data' => ['slots' => []]];
    }

    $variations = [];
    foreach ($variation_ids as $vid) {
      $v = ProductVariation::load($vid);
      if ($v) {
        $variations[$vid] = $v;
      }
    }
    if (!$variations) {
      return ['status' => 200, 'data' => ['slots' => []]];
    }

    $filtered_ids = [];
    foreach ($variation_ids as $vid) {
      if (!isset($variations[$vid])) {
        continue;
      }
      $v = $variations[$vid];
      if (court_booking_variation_is_configured($v) && court_booking_variation_has_published_court_node($v)) {
        $filtered_ids[] = $vid;
      }
    }

    if ($filtered_ids) {
      $slot_lens = [];
      foreach ($filtered_ids as $vid) {
        $v = $variations[$vid];
        $slot_lens[] = max(1, (int) $this->availabilityManager->getLessonSlotLength($v));
      }
      if (!CourtBookingPlayDurationGrid::playMinutesValidForSlots($play_minutes, $slot_lens)) {
        return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid play duration for these courts.')]];
      }
    }

    $slots = $filtered_ids
      ? $this->slotBooking->buildBufferSlotCandidatesForDay($filtered_ids, $ymd, $play_minutes, $quantity, $account)
      : [];

    return ['status' => 200, 'data' => ['slots' => $slots]];
  }

  /**
   * Validates and adds a court line to the cart.
   *
   * @param array $data
   *   Keys: variation_id, start, end, quantity.
   * @param array $options
   *   - include_legacy_redirect: bool (default TRUE for web JSON).
   *   - include_rest_fields: bool (default FALSE); adds order_id, order_item_id, totals.
   *
   * @return array{status: int, data: array}
   */
  public function addBookingLineItem(AccountInterface $account, array $data, array $options = []): array {
    $include_legacy_redirect = $options['include_legacy_redirect'] ?? TRUE;
    $include_rest_fields = $options['include_rest_fields'] ?? FALSE;

    $variation_id = (int) ($data['variation_id'] ?? 0);
    $start_raw = $data['start'] ?? '';
    $end_raw = $data['end'] ?? '';
    $quantity = max(1, (int) ($data['quantity'] ?? 1));

    if (!$variation_id || $start_raw === '' || $end_raw === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing variation or time range.')]];
    }

    $variation = ProductVariation::load($variation_id);
    if (!$variation) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid product variation.')]];
    }

    if (!court_booking_variation_is_configured($variation)) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('This court is not enabled for the booking page.')]];
    }

    if (!court_booking_variation_has_published_court_node($variation)) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('This court is not available for booking.')]];
    }

    $validation = $this->slotBooking->validateLessonSlot(
      $variation,
      (string) $start_raw,
      (string) $end_raw,
      $quantity,
      $account,
    );
    if (!$validation['ok']) {
      return ['status' => $validation['status'], 'data' => ['message' => $validation['message']]];
    }

    /** @var \DateTimeImmutable $start */
    $start = $validation['start'];
    /** @var \DateTimeImmutable $end */
    $end = $validation['end'];
    $billing_units = (int) $validation['billing_units'];

    if (!$this->batUnitEnsurer->ensureBookableUnit($variation)) {
      $this->logger->error('No BAT unit could be resolved for variation @id.', ['@id' => $variation->id()]);
      return ['status' => 500, 'data' => [
        'message' => (string) $this->t('This court is not linked to a bookable unit yet. In Commerce BAT settings, confirm lesson unit bundle and unit type, then run database updates (drush updb).'),
      ]];
    }

    $cb_config = $this->configFactory->get('court_booking.settings');
    $order_type = $cb_config->get('order_type_id') ?: 'default';
    $store = $this->currentStore->getStore();
    if (!$store) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('No active store is configured.')]];
    }

    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if (!$cart) {
      $cart = $this->cartProvider->createCart($order_type, $store, $account);
    }

    $order_item = $this->cartManager->createOrderItem($variation, (string) $quantity);
    if (!$order_item->hasField('field_cbat_rental_date')) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Order items are missing the rental date field.')]];
    }

    $this->slotBooking->applyRentalAndPrice($order_item, $variation, $start, $end, $billing_units);

    try {
      $this->cartManager->addOrderItem($cart, $order_item, FALSE, TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->error('Court booking add failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not add to cart. Please try again.')]];
    }

    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if ($cart && $include_rest_fields) {
      foreach ($cart->getItems() as $candidate) {
        if ((int) $candidate->id() === (int) $order_item->id()) {
          $order_item = $candidate;
          break;
        }
      }
    }
    $response = [
      'status' => 'ok',
    ];
    if ($include_legacy_redirect) {
      $redirect_url = Url::fromRoute('commerce_cart.page')->setAbsolute()->toString();
      $court_node = CourtBookingVariationThumbnail::courtNode($variation);
      if ($court_node && $court_node->access('view', $account)) {
        $redirect_url = $court_node->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
      $response['redirect'] = $redirect_url;
    }
    if ($include_rest_fields && $cart) {
      $this->orderRefresh->refresh($cart);
      $cart->save();
      $fresh_item_id = $order_item->id();
      $response['order_id'] = (int) $cart->id();
      $response['order_item_id'] = $fresh_item_id ? (int) $fresh_item_id : NULL;
      $response['total'] = $cart->getTotalPrice() ? $cart->getTotalPrice()->__toString() : NULL;
      $step_id = $this->checkoutOrderManager->getCheckoutStepId($cart);
      $response['checkout_url'] = Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $cart->id(),
        'step' => $step_id,
      ], ['absolute' => TRUE])->toString();
    }

    return ['status' => 200, 'data' => $response];
  }

  /**
   * Updates rental slot on a cart line item.
   *
   * @return array{status: int, data: array}
   */
  public function updateCartLineSlot(AccountInterface $account, OrderItemInterface $commerce_order_item, array $data): array {
    $start_raw = $data['start'] ?? '';
    $end_raw = $data['end'] ?? '';
    if ($start_raw === '' || $end_raw === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing start or end time.')]];
    }

    $purchased = $commerce_order_item->getPurchasedEntity();
    if (!$purchased instanceof ProductVariationInterface) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('This line item has no product variation.')]];
    }

    $quantity = max(1, (int) $commerce_order_item->getQuantity());
    $validation = $this->slotBooking->validateLessonSlot(
      $purchased,
      (string) $start_raw,
      (string) $end_raw,
      $quantity,
      $account,
    );
    if (!$validation['ok']) {
      return ['status' => $validation['status'], 'data' => ['message' => $validation['message']]];
    }

    /** @var \DateTimeImmutable $start */
    $start = $validation['start'];
    /** @var \DateTimeImmutable $end */
    $end = $validation['end'];
    $billing_units = (int) $validation['billing_units'];

    if (!$commerce_order_item->hasField('field_cbat_rental_date')) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Order items are missing the rental date field.')]];
    }

    try {
      $this->slotBooking->applyRentalAndPrice($commerce_order_item, $purchased, $start, $end, $billing_units);
      $commerce_order_item->save();
      $order = $commerce_order_item->getOrder();
      if ($order) {
        $this->orderRefresh->refresh($order);
        $order->save();
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Cart slot update failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not update booking. Please try again.')]];
    }

    $payload = ['status' => 'ok'];
    if ($order = $commerce_order_item->getOrder()) {
      $payload['order_id'] = (int) $order->id();
      $payload['order_item_id'] = (int) $commerce_order_item->id();
    }

    return ['status' => 200, 'data' => $payload];
  }

}
