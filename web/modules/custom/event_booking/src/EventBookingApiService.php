<?php

namespace Drupal\event_booking;

use Drupal\commerce\Context;
use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\commerce_stock\StockServiceManagerInterface;
use Drupal\court_booking\CourtBookingApiService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\event_booking\Portal\PortalUserClientInterface;

/**
 * Business logic for event ticket REST API.
 */
class EventBookingApiService {

  use StringTranslationTrait;

  /**
   * Constructs an EventBookingApiService.
   *
   * @param \Drupal\commerce_cart\CartManagerInterface $cartManager
   *   Commerce cart manager for creating and updating order items.
   * @param \Drupal\commerce_cart\CartProviderInterface $cartProvider
   *   Commerce cart provider for loading or creating draft orders.
   * @param \Drupal\commerce_order\OrderRefreshInterface $orderRefresh
   *   Refreshes order totals and availability after item changes.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager for loading nodes, orders, and commerce entities.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Field manager for resolving bundle–field relationships.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory for reading event_booking.settings and system.date.
   * @param \Drupal\global_module\Service\GlobalVariablesService $globalVariables
   *   Global variables service used for encryption/decryption of portal data.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   Generates absolute URLs for file entities (event images).
   * @param \Drupal\commerce_stock\StockServiceManagerInterface $stockServiceManager
   *   Stock service manager for per-variation stock level resolution.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for the event_booking channel.
   * @param \Drupal\court_booking\CourtBookingApiService $courtBookingApi
   *   Court booking API service for the unified bookings endpoint.
   * @param \Drupal\event_booking\Portal\PortalUserClientInterface $portalUserClient
   *   HTTP client abstraction for the portal user details API.
   */
  public function __construct(
    protected CartManagerInterface $cartManager,
    protected CartProviderInterface $cartProvider,
    protected OrderRefreshInterface $orderRefresh,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ConfigFactoryInterface $configFactory,
    protected GlobalVariablesService $globalVariables,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected StockServiceManagerInterface $stockServiceManager,
    protected LoggerInterface $logger,
    protected CourtBookingApiService $courtBookingApi,
    protected PortalUserClientInterface $portalUserClient,
  ) {}

  /**
   * Verifies a portal user ID against the portal API and the Drupal identity.
   *
   * Loads the portal user record identified by $portal_user_id and confirms
   * that the returned email address matches the email of the currently
   * authenticated Drupal account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently authenticated Drupal account.
   * @param string $portal_user_id
   *   The portal-side user identifier submitted by the client.
   *
   * @return array{status: int, data: array}
   *   A status/data envelope; status mirrors HTTP response codes.
   */
  public function verifyPortalUser(AccountInterface $account, string $portal_user_id): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    $drupal_email = $this->getAccountEmail($account);
    if ($drupal_email === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Current account has no email.')]];
    }

    if (!$this->portalUserClient->isConfigured()) {
      return ['status' => 503, 'data' => ['message' => (string) $this->t('Portal API is not configured.')]];
    }

    try {
      $payload = $this->portalUserClient->fetchByIdentifier($portal_user_id);
      if ($payload === NULL) {
        return ['status' => 404, 'data' => ['message' => (string) $this->t('Portal user not found for the given id.')]];
      }

      $resolved_email = $this->extractPortalEmail($payload, $portal_user_id);
      if ($resolved_email === NULL) {
        return ['status' => 502, 'data' => ['message' => (string) $this->t('Portal response did not include a verifiable email.')]];
      }
      if (mb_strtolower(trim($resolved_email)) !== mb_strtolower(trim($drupal_email))) {
        $this->logger->notice('event_booking portal verify mismatch for uid @uid.', ['@uid' => $account->id()]);
        return ['status' => 403, 'data' => ['message' => (string) $this->t('Portal identity does not match the authenticated user.')]];
      }

      $return_user_id = $payload['userId'] ?? $portal_user_id;
      return ['status' => 200, 'data' => [
        'verified' => TRUE,
        'portal_user_id' => is_scalar($return_user_id) ? (string) $return_user_id : $portal_user_id,
        'email' => $drupal_email,
      ]];
    }
    catch (\Throwable $e) {
      $this->logger->error('event_booking portal verify failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 503, 'data' => ['message' => (string) $this->t('Portal verification service unavailable.')]];
    }
  }

  /**
   * Resolves the portal userId for the authenticated Drupal account.
   *
   * Looks up the portal user by the account's email address and verifies
   * that the portal record matches before returning the portal userId.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently authenticated Drupal account.
   *
   * @return array{status: int, data: array}
   *   A status/data envelope; status mirrors HTTP response codes.
   */
  public function resolvePortalUserContext(AccountInterface $account): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    $drupal_email = $this->getAccountEmail($account);
    if ($drupal_email === '') {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Current account has no email.')]];
    }

    if (!$this->portalUserClient->isConfigured()) {
      return ['status' => 503, 'data' => ['message' => (string) $this->t('Portal API is not configured.')]];
    }

    try {
      $payload = $this->portalUserClient->fetchByIdentifier($drupal_email);
      if ($payload === NULL) {
        return ['status' => 404, 'data' => ['message' => (string) $this->t('Portal user not found for this account.')]];
      }

      $resolved_email = $this->extractPortalEmail($payload, $drupal_email);
      if ($resolved_email === NULL) {
        return ['status' => 502, 'data' => ['message' => (string) $this->t('Portal response did not include a verifiable email.')]];
      }
      if (mb_strtolower(trim($resolved_email)) !== mb_strtolower(trim($drupal_email))) {
        return ['status' => 403, 'data' => ['message' => (string) $this->t('Portal profile email does not match the authenticated user.')]];
      }

      $raw_portal_id = $payload['userId'] ?? NULL;
      if ($raw_portal_id === NULL || is_array($raw_portal_id) || is_object($raw_portal_id)) {
        return ['status' => 502, 'data' => ['message' => (string) $this->t('Portal response did not include a userId.')]];
      }
      $portal_user_id = trim((string) $raw_portal_id);
      if ($portal_user_id === '') {
        return ['status' => 502, 'data' => ['message' => (string) $this->t('Portal returned an empty userId.')]];
      }

      return ['status' => 200, 'data' => [
        'portal_user_id' => $portal_user_id,
        'email' => $drupal_email,
        'source' => 'portal_user_details',
        'lookup' => 'drupal_account_email',
      ]];
    }
    catch (\Throwable $e) {
      $this->logger->error('event_booking portal context failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 503, 'data' => ['message' => (string) $this->t('Portal context service unavailable.')]];
    }
  }

  /**
   * Adds event tickets to the authenticated user's active event cart.
   *
   * Validates the variation against configured settings and stock levels,
   * then creates a cart if none exists and appends the order item.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The authenticated user who is adding tickets.
   * @param array<string, mixed> $data
   *   Request payload. Recognised keys:
   *   - variation_id: (int, optional) Ticket product variation to add;
   *     falls back to configured default_variation_id.
   *   - quantity: (int, optional) Number of tickets; defaults to 1.
   *
   * @return array{status: int, data: array}
   *   A status/data envelope; status mirrors HTTP response codes.
   */
  public function addTickets(AccountInterface $account, array $data): array {
    $settings = $this->configFactory->get('event_booking.settings');
    $store = $this->loadEventStore();
    if (!$store instanceof StoreInterface) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Event store is not configured or invalid.')]];
    }

    $order_type = $settings->get('order_type_id') ?: 'default';
    $variation_id = (int) ($data['variation_id'] ?? $settings->get('default_variation_id') ?? 0);
    $quantity = max(1, (int) ($data['quantity'] ?? 1));
    $max_q = max(1, (int) ($settings->get('max_quantity_per_request') ?? 500));

    if ($quantity > $max_q) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Quantity exceeds the maximum allowed per request (@max).', ['@max' => $max_q])]];
    }
    if ($variation_id <= 0) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Missing or invalid variation_id.')]];
    }

    $allowed = array_filter(array_map('intval', (array) $settings->get('allowed_variation_ids')));
    if ($allowed && !in_array($variation_id, $allowed, TRUE)) {
      return ['status' => 403, 'data' => ['message' => (string) $this->t('This ticket variation is not allowed for API booking.')]];
    }

    $variation = ProductVariation::load($variation_id);
    if (!$variation instanceof ProductVariationInterface || !$variation->isPublished()) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid or unavailable ticket variation.')]];
    }
    if (!$this->variationBelongsToStore($variation, $store)) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('This ticket is not sold in the configured event store.')]];
    }

    $cart = $this->cartProvider->getCart($order_type, $store, $account)
      ?? $this->cartProvider->createCart($order_type, $store, $account);

    $stock_check = $this->evaluateTicketStock($variation, $store, $account, $cart, $quantity);
    if ($stock_check !== NULL) {
      return $stock_check;
    }

    $order_item = $this->cartManager->createOrderItem($variation, (string) $quantity);
    try {
      $this->cartManager->addOrderItem($cart, $order_item, FALSE, TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->error('event_booking add to cart failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not add tickets to cart.')]];
    }

    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if (!$cart instanceof OrderInterface) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not reload cart.')]];
    }
    $this->orderRefresh->refresh($cart);

    return ['status' => 200, 'data' => [
      'status' => 'ok',
      'order_id' => (int) $cart->id(),
      'order_item_id' => (int) $order_item->id(),
      'variation_id' => $variation_id,
      'quantity' => $quantity,
      'total' => $cart->getTotalPrice() ? $cart->getTotalPrice()->__toString() : NULL,
      'checkout_url' => Url::fromRoute('commerce_checkout.form', [
        'commerce_order' => $cart->id(),
        'step' => 'order_information',
      ], ['absolute' => TRUE])->toString(),
    ]];
  }

  /**
   * Returns the serialised event cart summary for the current user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The authenticated user whose active event cart should be loaded.
   *
   * @return array{status: int, data: array}
   *   A status/data envelope; status mirrors HTTP response codes.
   */
  public function getCartPayload(AccountInterface $account): array {
    $store = $this->loadEventStore();
    if (!$store instanceof StoreInterface) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Event store is not configured or invalid.')]];
    }
    $settings = $this->configFactory->get('event_booking.settings');
    $order_type = $settings->get('order_type_id') ?: 'default';
    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if (!$cart instanceof OrderInterface) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('No active event cart.')]];
    }
    $this->orderRefresh->refresh($cart);
    return ['status' => 200, 'data' => $this->serializeCart($cart)];
  }

  /**
   * Clears all line items from the current user's active event cart.
   *
   * @return array{status: int, data: array}
   */
  public function clearCart(AccountInterface $account): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    $store = $this->loadEventStore();
    if (!$store instanceof StoreInterface) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Event store is not configured or invalid.')]];
    }
    $settings = $this->configFactory->get('event_booking.settings');
    $order_type = $settings->get('order_type_id') ?: 'default';
    $cart = $this->cartProvider->getCart($order_type, $store, $account);
    if (!$cart instanceof OrderInterface) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('No active event cart.')]];
    }
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

    $removed_count = 0;
    try {
      foreach ($cart->getItems() as $item) {
        $cart->removeItem($item);
        $item->delete();
        $removed_count++;
      }
      $this->orderRefresh->refresh($cart);
      $cart->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('event_booking clear cart failed for order @order: @msg', [
        '@order' => (int) $cart->id(),
        '@msg' => $e->getMessage(),
      ]);
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Could not clear event cart.')]];
    }

    return ['status' => 200, 'data' => [
      'status' => 'ok',
      'message' => (string) $this->t('Cart cleared.'),
      'order_id' => (int) $cart->id(),
      'removed_count' => $removed_count,
      'remaining_items' => count($cart->getItems()),
    ]];
  }

  /**
   * Builds a receipt payload for a completed order.
   *
   * Serialises line items and resolves linked event node details for each
   * purchased ticket variation. Only completed orders return a receipt;
   * draft/cancelled orders respond with a 409 status.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order for which to build the receipt.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account requesting the receipt; must match the order customer.
   *
   * @return array{status: int, data: array}
   *   A status/data envelope; status mirrors HTTP response codes.
   */
  public function buildReceipt(OrderInterface $order, AccountInterface $account): array {
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
    if ($fresh->getState()->getId() !== 'completed') {
      return ['status' => 409, 'data' => [
        'message' => (string) $this->t('Receipt is only available for completed orders.'),
        'state' => $fresh->getState()->getId(),
      ]];
    }

    $settings = $this->configFactory->get('event_booking.settings');
    $date_field = (string) ($settings->get('event_date_range_field') ?: 'field_event_date_time');
    $image_field = (string) ($settings->get('event_image_field') ?: 'field_event_image');
    $location_field = (string) ($settings->get('event_location_field') ?: 'field_event_location');

    [$lines, $events] = $this->buildReceiptLines($fresh, $account, $settings, $date_field, $image_field, $location_field);

    return ['status' => 200, 'data' => [
      'order_id' => (int) $fresh->id(),
      'state' => $fresh->getState()->getId(),
      'total' => $fresh->getTotalPrice() ? $fresh->getTotalPrice()->__toString() : NULL,
      'line_items' => $lines,
      'events' => array_values($events),
    ]];
  }

  /**
   * Returns Commerce price metadata for a ticket variation in the event store.
   *
   * @return array{status: int, data: array}
   */
  public function getTicketVariationPricing(AccountInterface $account, ProductVariationInterface $variation): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    $store = $this->loadEventStore();
    if (!$store instanceof StoreInterface) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Event store is not configured or invalid.')]];
    }
    if (!$variation->isPublished()) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('Ticket variation not found or not available.')]];
    }
    if (!$this->variationBelongsToStore($variation, $store)) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('This ticket is not sold in the configured event store.')]];
    }

    $price = $variation->getPrice();
    $data = [
      'variation_id' => (int) $variation->id(),
      'title' => $variation->getTitle(),
      'sku' => $variation->getSku(),
      'published' => $variation->isPublished(),
      'price' => $price ? $price->__toString() : NULL,
      'price_number' => $price ? $price->getNumber() : NULL,
      'currency_code' => $price ? $price->getCurrencyCode() : NULL,
      'list_price' => NULL,
      'list_price_number' => NULL,
      'list_currency_code' => NULL,
    ];
    if (method_exists($variation, 'getListPrice')) {
      $list = $variation->getListPrice();
      if ($list) {
        $data['list_price'] = $list->__toString();
        $data['list_price_number'] = $list->getNumber();
        $data['list_currency_code'] = $list->getCurrencyCode();
      }
    }

    return ['status' => 200, 'data' => $data];
  }

  /**
   * Returns paginated upcoming or completed booked events for the current user.
   *
   * Queries completed Commerce orders for the account, resolves the linked
   * event nodes, and returns a paginated, bucket-filtered list.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The authenticated account whose orders are queried.
   * @param string $bucket
   *   'upcoming' returns events with a future end time; 'completed' returns
   *   events whose end time is in the past.
   * @param array{page?: int, limit?: int, q?: string} $params
   *   Pagination and search parameters. All keys are optional.
   *
   * @return array{status: int, data: array}
   *   A status/data envelope; status mirrors HTTP response codes.
   */
  public function getMyBookedEvents(AccountInterface $account, string $bucket, array $params): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    if (!in_array($bucket, ['upcoming', 'completed'], TRUE)) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid events bucket.')]];
    }

    $page = max(0, (int) ($params['page'] ?? 0));
    $limit = max(1, min(50, (int) ($params['limit'] ?? 10) ?: 10));
    $q = mb_strtolower(trim((string) ($params['q'] ?? '')));

    $settings = $this->configFactory->get('event_booking.settings');
    $bundle = trim((string) ($settings->get('event_node_bundle') ?: 'events'));
    $field = trim((string) ($settings->get('event_ticket_variation_field') ?: 'field_prod_event_variation'));
    $date_field = (string) ($settings->get('event_date_range_field') ?: 'field_event_date_time');
    $image_field = (string) ($settings->get('event_image_field') ?: 'field_event_image');
    $location_field = (string) ($settings->get('event_location_field') ?: 'field_event_location');

    if ($bundle === '' || $field === '') {
      return ['status' => 200, 'data' => $this->buildPagerResponse([], $page, $limit)];
    }

    [$bundle, $has_field] = $this->resolveBundleForEventVariationField($bundle, $field);
    if (!$has_field || $bundle === '') {
      return ['status' => 200, 'data' => $this->buildPagerResponse([], $page, $limit)];
    }

    $booking_map = $this->loadCompletedBookedVariationOrderMap((int) $account->id());
    if ($booking_map['variation_ids'] === []) {
      return ['status' => 200, 'data' => $this->buildPagerResponse([], $page, $limit)];
    }

    $node_ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition($field . '.target_id', $booking_map['variation_ids'], 'IN')
      ->execute();
    if (!$node_ids) {
      return ['status' => 200, 'data' => $this->buildPagerResponse([], $page, $limit)];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_values($node_ids));
    $field_map = [
      'date' => $date_field,
      'image' => $image_field,
      'location' => $location_field,
    ];
    $items = $this->buildBookedEventRows(
      $nodes, $account, $bucket, $field_map, $field, $booking_map['variation_orders'], $q
    );
    $items = $this->sortAndStripBookedEventItems($items, $bucket);

    return ['status' => 200, 'data' => $this->buildPagerResponse($items, $page, $limit)];
  }

  /**
   * Returns a unified segmented bookings payload for mobile clients.
   *
   * Combines court and event bookings into a single response with separate
   * per-type pagination segments. The 'kind' parameter controls which
   * segments are populated ('all', 'court', or 'event').
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The authenticated account whose bookings are fetched.
   * @param array<string, int|string> $params
   *   Accepted keys: bucket (upcoming|past), kind (all|court|event), q
   *   (search string), sport_tid (taxonomy term ID), court_page, court_limit,
   *   event_page, event_limit.
   *
   * @return array{status: int, data: array}
   *   A status/data envelope; status mirrors HTTP response codes.
   */
  public function getUnifiedBookings(AccountInterface $account, array $params): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }

    $bucket = in_array((string) ($params['bucket'] ?? ''), ['upcoming', 'past'], TRUE)
      ? (string) $params['bucket']
      : 'upcoming';
    $kind = in_array((string) ($params['kind'] ?? ''), ['all', 'court', 'event'], TRUE)
      ? (string) $params['kind']
      : 'all';
    $q = trim((string) ($params['q'] ?? ''));
    $sport_tid = max(0, (int) ($params['sport_tid'] ?? 0));

    $court_limit = max(1, min(50, (int) ($params['court_limit'] ?? 10)));
    $event_limit = max(1, min(50, (int) ($params['event_limit'] ?? 10)));

    $court_params = [
      'page' => max(0, (int) ($params['court_page'] ?? 0)),
      'limit' => $court_limit,
      'q' => $q,
      'sport_tid' => $sport_tid,
    ];
    $event_params = [
      'page' => max(0, (int) ($params['event_page'] ?? 0)),
      'limit' => $event_limit,
      'q' => $q,
    ];

    $segments = [
      'court' => ['rows' => [], 'pager' => $this->buildPagerResponse([], 0, $court_limit)['pager']],
      'event' => ['rows' => [], 'pager' => $this->buildPagerResponse([], 0, $event_limit)['pager']],
    ];

    if ($kind === 'all' || $kind === 'court') {
      $error = $this->appendCourtSegment($account, $bucket, $court_params, $segments);
      if ($error !== NULL) {
        return $error;
      }
    }

    if ($kind === 'all' || $kind === 'event') {
      $error = $this->appendEventSegment($account, $bucket, $event_params, $segments);
      if ($error !== NULL) {
        return $error;
      }
    }

    return ['status' => 200, 'data' => [
      'bucket' => $bucket,
      'filters' => ['q' => $q, 'sport_tid' => $sport_tid, 'kind' => $kind],
      'segments' => $segments,
    ]];
  }

  /**
   * Returns the current Unix timestamp; override in tests for determinism.
   */
  protected function getCurrentTime(): int {
    return \Drupal::time()->getCurrentTime();
  }

  /**
   * Iterates loaded nodes and returns the serialized, filtered, sortable rows.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Candidate event nodes to process.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account used for access checks.
   * @param string $bucket
   *   Either 'upcoming' or 'completed'.
   * @param array<string, string> $field_map
   *   Keys: 'date', 'image', 'location' — machine names of the relevant node
   *   fields used for serialisation and timestamp extraction.
   * @param string $variation_field
   *   Field name on the event node referencing the ticket product variation.
   * @param array<int, int[]> $variation_orders
   *   Map of variation ID → completed order IDs for the current account.
   * @param string $q
   *   Normalised (lowercase, trimmed) search string; empty string to skip.
   *
   * @return array<int, array<string, mixed>>
   *   Serialised rows ready for sorting and pagination.
   */
  protected function buildBookedEventRows(
    array $nodes,
    AccountInterface $account,
    string $bucket,
    array $field_map,
    string $variation_field,
    array $variation_orders,
    string $q,
  ): array {
    $date_field = $field_map['date'];
    $image_field = $field_map['image'];
    $location_field = $field_map['location'];
    $now = $this->getCurrentTime();
    $seen = [];
    $items = [];

    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $nid = (int) $node->id();
      if (isset($seen[$nid]) || !$node->access('view', $account)) {
        continue;
      }
      $seen[$nid] = TRUE;

      $timestamps = $this->extractBucketTimestamps($node, $bucket, $date_field, $now);
      if ($timestamps === NULL) {
        continue;
      }
      [$start_ts, $effective_end] = $timestamps;

      $order_ids = $this->orderIdsForEventNode($node, $variation_field, $variation_orders);
      if ($order_ids === []) {
        continue;
      }

      $serialized = $this->serializeEventNode($node, $date_field, $image_field, $location_field);
      if ($q !== '' && !$this->matchesEventSearch($node, $serialized, $q, $location_field)) {
        continue;
      }

      $items[] = [
        'nid' => $serialized['nid'] ?? $nid,
        'order_id' => $order_ids[0],
        'order_ids' => $order_ids,
        '_sort_start' => $start_ts ?? $effective_end,
        '_sort_end' => $effective_end,
      ] + $serialized;
    }

    return $items;
  }

  /**
   * Validates node timestamps against the requested bucket and returns them.
   *
   * Returns NULL when the node has no parseable dates or does not belong to
   * the requested bucket (upcoming / completed).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node to inspect.
   * @param string $bucket
   *   'upcoming' or 'completed'.
   * @param string $date_field
   *   Machine name of the date-range field on the node.
   * @param int $now
   *   Current Unix timestamp used for upcoming/completed comparison.
   *
   * @return array{0: int|null, 1: int}|null
   *   [start_ts, effective_end_ts] when the node should be included,
   *   or NULL to skip.
   */
  protected function extractBucketTimestamps(NodeInterface $node, string $bucket, string $date_field, int $now): ?array {
    [$start_ts, $end_ts] = $this->extractEventTimestamps($node, $date_field);
    $effective_end = $end_ts ?? $start_ts;
    if ($effective_end === NULL) {
      return NULL;
    }
    $is_upcoming = $effective_end > $now;
    if (($bucket === 'upcoming' && !$is_upcoming) || ($bucket === 'completed' && $is_upcoming)) {
      return NULL;
    }
    return [$start_ts, $effective_end];
  }

  /**
   * Sorts booked-event item rows and strips internal sort keys.
   *
   * Upcoming events are sorted ascending by start time; completed events
   * are sorted descending (most recent first).
   *
   * @param array<int, array<string, mixed>> $items
   *   Unsorted rows as produced by buildBookedEventRows().
   * @param string $bucket
   *   'upcoming' or 'completed'.
   *
   * @return array<int, array<string, mixed>>
   *   Sorted rows with '_sort_start' and '_sort_end' keys removed.
   */
  protected function sortAndStripBookedEventItems(array $items, string $bucket): array {
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

  /**
   * Fetches and normalises the court bookings segment for getUnifiedBookings().
   *
   * Updates $segments['court'] in place on success. Returns NULL on success
   * or an error response array when the downstream API call fails.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The authenticated account.
   * @param string $bucket
   *   'upcoming' or 'past'.
   * @param array<string, mixed> $court_params
   *   Parameters forwarded to courtBookingApi->buildMyBookingsResponse().
   * @param array<string, array<string, mixed>> $segments
   *   Segments array mutated in place on success.
   *
   * @return array{status: int, data: array}|null
   *   NULL on success; error envelope on failure.
   */
  protected function appendCourtSegment(AccountInterface $account, string $bucket, array $court_params, array &$segments): ?array {
    $result = $this->courtBookingApi->buildMyBookingsResponse($account, $bucket, $court_params);
    if ($result['status'] !== 200) {
      return $result;
    }
    $rows = [];
    foreach ((array) ($result['data']['rows'] ?? []) as $row) {
      if (is_array($row)) {
        $rows[] = ['kind' => 'court'] + $row;
      }
    }
    $segments['court'] = [
      'rows' => $rows,
      'pager' => (array) ($result['data']['pager'] ?? $segments['court']['pager']),
    ];
    return NULL;
  }

  /**
   * Fetches and normalises the event bookings segment for getUnifiedBookings().
   *
   * Updates $segments['event'] in place on success. Returns NULL on success
   * or an error response array when the downstream API call fails.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The authenticated account.
   * @param string $bucket
   *   'upcoming' or 'past' (mapped to 'upcoming'/'completed' for events).
   * @param array<string, mixed> $event_params
   *   Parameters forwarded to getMyBookedEvents().
   * @param array<string, array<string, mixed>> $segments
   *   Segments array mutated in place on success.
   *
   * @return array{status: int, data: array}|null
   *   NULL on success; error envelope on failure.
   */
  protected function appendEventSegment(AccountInterface $account, string $bucket, array $event_params, array &$segments): ?array {
    $event_bucket = $bucket === 'past' ? 'completed' : 'upcoming';
    $result = $this->getMyBookedEvents($account, $event_bucket, $event_params);
    if ($result['status'] !== 200) {
      return $result;
    }
    $rows = [];
    foreach ((array) ($result['data']['rows'] ?? []) as $row) {
      if (is_array($row)) {
        $rows[] = ['kind' => 'event'] + $row;
      }
    }
    $segments['event'] = [
      'rows' => $rows,
      'pager' => (array) ($result['data']['pager'] ?? $segments['event']['pager']),
    ];
    return NULL;
  }

  /**
   * @param array<int, array<string, mixed>> $rows
   * @return array{rows: array<int, array<string, mixed>>, pager: array<string, int|string>}
   */
  protected function buildPagerResponse(array $rows, int $page, int $limit): array {
    $total = count($rows);
    $total_pages = $limit > 0 ? max(1, (int) ceil($total / $limit)) : 1;
    $offset = $page * $limit;
    $paged_rows = $limit > 0 ? array_slice($rows, $offset, $limit) : $rows;
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

  /**
   * @return array{0:int|null,1:int|null}
   */
  protected function extractEventTimestamps(NodeInterface $node, string $date_field): array {
    if (!$node->hasField($date_field) || $node->get($date_field)->isEmpty()) {
      return [NULL, NULL];
    }
    $item = $node->get($date_field)->first();
    if (!$item) {
      return [NULL, NULL];
    }
    $value = $item->getValue();
    $start = isset($value['value']) ? $this->toTimestamp((string) $value['value']) : NULL;
    $end = isset($value['end_value']) ? $this->toTimestamp((string) $value['end_value']) : NULL;
    return [$start, $end];
  }

  /**
   * Converts a UTC datetime string to a Unix timestamp.
   *
   * @param string $value
   *   A UTC datetime string compatible with DrupalDateTime.
   *
   * @return int|null
   *   Unix timestamp, or NULL when the value cannot be parsed.
   */
  protected function toTimestamp(string $value): ?int {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    try {
      $date = new DrupalDateTime($value, 'UTC');
      return $date->getTimestamp();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @param array<string, mixed> $serialized
   */
  protected function matchesEventSearch(NodeInterface $node, array $serialized, string $q, string $location_field): bool {
    if ($q === '') {
      return TRUE;
    }
    $haystacks = [
      mb_strtolower($node->getTitle()),
      isset($serialized['location']) ? mb_strtolower((string) $serialized['location']) : '',
    ];
    if ($node->hasField('field_event_categories') && !$node->get('field_event_categories')->isEmpty()) {
      $haystacks[] = mb_strtolower((string) $node->get('field_event_categories')->value);
    }
    if ($location_field !== 'field_event_categories'
      && $node->hasField($location_field)
      && !$node->get($location_field)->isEmpty()) {
      $haystacks[] = mb_strtolower((string) $node->get($location_field)->value);
    }
    foreach ($haystacks as $text) {
      if ($text !== '' && str_contains($text, $q)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Sums the quantity of a specific variation already present in an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order (cart) to inspect.
   * @param int $variation_id
   *   The product variation ID to count.
   *
   * @return float
   *   Total quantity of that variation across all order items.
   */
  protected function countVariationQuantityInCart(OrderInterface $order, int $variation_id): float {
    $sum = 0.0;
    foreach ($order->getItems() as $item) {
      $purchased = $item->getPurchasedEntity();
      if ($purchased instanceof ProductVariationInterface && (int) $purchased->id() === $variation_id) {
        $sum += (float) $item->getQuantity();
      }
    }
    return $sum;
  }

  /**
   * Formats a float quantity as a human-readable string for user messages.
   *
   * Integer-valued floats are rendered without decimal places.
   *
   * @param float $quantity
   *   The quantity to format.
   *
   * @return string
   *   A trimmed decimal representation.
   */
  protected function formatQuantityForMessage(float $quantity): string {
    if (abs($quantity - round($quantity)) < 1e-6) {
      return (string) (int) round($quantity);
    }
    return rtrim(rtrim(sprintf('%.4f', $quantity), '0'), '.') ?: '0';
  }

  /**
   * Returns TRUE for node base fields and known system fields to skip.
   *
   * Used by serializeNodeFields() to omit fields that are redundant or
   * irrelevant in an API response (e.g. nid, uid, revision_*).
   *
   * @param string $field_name
   *   The field machine name to check.
   *
   * @return bool
   *   TRUE if the field should be omitted from the serialised output.
   */
  protected function isSkippedNodeField(string $field_name): bool {
    static $skipped = [
      'nid', 'uuid', 'vid', 'type',
      'revision_timestamp', 'revision_uid', 'revision_log',
      'revision_default', 'revision_translation_affected',
      'langcode', 'default_langcode', 'status', 'uid', 'title',
      'created', 'changed', 'promote', 'sticky', 'path',
    ];
    return in_array($field_name, $skipped, TRUE) || str_starts_with($field_name, 'revision_');
  }

  /**
   * @param array<string, mixed> $value
   * @return array<string, mixed>
   */
  protected function normalizeFieldDateValuesToSiteTimezone(array $value, string $field_type): array {
    if (!in_array($field_type, ['datetime', 'daterange'], TRUE)) {
      return $value;
    }
    if (isset($value['value']) && is_string($value['value']) && $value['value'] !== '') {
      $value['value'] = $this->formatUtcStringToSiteIso($value['value']) ?? $value['value'];
    }
    if (isset($value['end_value']) && is_string($value['end_value']) && $value['end_value'] !== '') {
      $value['end_value'] = $this->formatUtcStringToSiteIso($value['end_value']) ?? $value['end_value'];
    }
    return $value;
  }

  /**
   * Returns the site's default timezone identifier string.
   *
   * Falls back to 'UTC' when the system.date configuration is absent.
   *
   * @return string
   *   A PHP timezone identifier (e.g. 'Asia/Dubai', 'UTC').
   */
  protected function siteTimezoneId(): string {
    $tz = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?? 'UTC');
    return $tz !== '' ? $tz : 'UTC';
  }

  /**
   * Converts a UTC datetime string to an ISO 8601 string in the site timezone.
   *
   * @param string $raw
   *   A UTC datetime string (e.g. from a Drupal datetime field value).
   *
   * @return string|null
   *   An ISO 8601 string in the site timezone, or NULL on parse failure.
   */
  protected function formatUtcStringToSiteIso(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    try {
      $utc = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
      return $utc->setTimezone(new \DateTimeZone($this->siteTimezoneId()))->format(DATE_ATOM);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Builds receipt line items and event map for buildReceipt().
   *
   * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
   */
  private function buildReceiptLines(
    OrderInterface $order,
    AccountInterface $account,
    ImmutableConfig $settings,
    string $date_field,
    string $image_field,
    string $location_field,
  ): array {
    $lines = [];
    $events = [];
    $cache = [];

    foreach ($order->getItems() as $item) {
      $purchased = $item->getPurchasedEntity();
      $line = [
        'order_item_id' => (int) $item->id(),
        'title' => $item->getTitle(),
        'quantity' => (string) $item->getQuantity(),
        'variation_id' => $purchased instanceof ProductVariationInterface ? (int) $purchased->id() : NULL,
      ];

      if ($purchased instanceof ProductVariationInterface) {
        $node = $this->resolveEventNodeForReceiptLine($purchased, $account, $settings, $cache);
        if ($node instanceof NodeInterface) {
          $target_id = (int) $node->id();
          $serialized = $this->serializeEventNode($node, $date_field, $image_field, $location_field);
          $line['event_nid'] = $target_id;
          $line['event'] = $serialized;
          $events[$target_id] = $serialized;
        }
      }
      $lines[] = $line;
    }

    return [$lines, $events];
  }

  /**
   * @param array<string, \Drupal\node\NodeInterface|null> $cache
   */
  private function resolveEventNodeForReceiptLine(
    ProductVariationInterface $variation,
    AccountInterface $account,
    ImmutableConfig $settings,
    array &$cache,
  ): ?NodeInterface {
    $vid = (string) $variation->id();
    if (array_key_exists($vid, $cache)) {
      return $cache[$vid];
    }
    $node = $this->findEventNodeByReverseTicketVariationReference($variation, $settings);
    if (!$node instanceof NodeInterface) {
      $node = $this->findEventNodeViaLegacyVariationField($variation, $settings);
    }
    if (!$node instanceof NodeInterface || !$node->access('view', $account)) {
      $cache[$vid] = NULL;
      return NULL;
    }
    $cache[$vid] = $node;
    return $node;
  }

  private function findEventNodeByReverseTicketVariationReference(
    ProductVariationInterface $variation,
    ImmutableConfig $settings,
  ): ?NodeInterface {
    $bundle = trim((string) ($settings->get('event_node_bundle') ?: 'events'));
    $field = trim((string) ($settings->get('event_ticket_variation_field') ?: 'field_prod_event_variation'));
    if ($bundle === '' || $field === '') {
      return NULL;
    }

    [$resolved_bundle, $has_field] = $this->resolveBundleForEventVariationField($bundle, $field);
    if (!$has_field) {
      $this->logger->warning('event_booking receipt: field @field not found on any node bundle (tried @bundle).', [
        '@field' => $field,
        '@bundle' => $bundle,
      ]);
      return NULL;
    }
    if ($resolved_bundle !== $bundle) {
      $this->logger->notice('event_booking receipt: using node bundle @bundle for field @field (configured @original had no definition).', [
        '@bundle' => $resolved_bundle,
        '@field' => $field,
        '@original' => $bundle,
      ]);
    }

    $nids = $this->queryEventNodeNidsByVariation($resolved_bundle, $field, (int) $variation->id());
    if (!$nids) {
      return NULL;
    }
    if (count($nids) > 1) {
      $this->logger->notice('event_booking receipt: multiple event nodes reference variation @vid; using lowest nid.', [
        '@vid' => (string) $variation->id(),
      ]);
    }
    $node = $this->entityTypeManager->getStorage('node')->load((int) $nids[0]);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Executes access-checked node query with anonymous bypass fallback.
   *
   * @return int[]
   */
  private function queryEventNodeNidsByVariation(string $bundle, string $field, int $variation_id): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $apply = static function ($q) use ($bundle, $field, $variation_id): void {
      $q->condition('type', $bundle)
        ->condition($field . '.target_id', $variation_id)
        ->sort('nid', 'ASC')
        ->range(0, 2);
    };

    $q = $storage->getQuery()->accessCheck(TRUE);
    $apply($q);
    $nids = $q->execute();

    if (!$nids) {
      $q2 = $storage->getQuery()->accessCheck(FALSE);
      $apply($q2);
      $nids = $q2->execute() ?: [];
    }

    return array_values($nids);
  }

  private function findEventNodeViaLegacyVariationField(
    ProductVariationInterface $variation,
    ImmutableConfig $settings,
  ): ?NodeInterface {
    $legacy = trim((string) ($settings->get('variation_event_reference_field') ?: ''));
    if ($legacy === '' || !$variation->hasField($legacy) || $variation->get($legacy)->isEmpty()) {
      return NULL;
    }
    $nid = (int) $variation->get($legacy)->target_id;
    if ($nid <= 0) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * @return array{0:string,1:bool}
   */
  private function resolveBundleForEventVariationField(string $bundle, string $field): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    if (isset($definitions[$field])) {
      return [$bundle, TRUE];
    }
    $storage_def = $this->entityFieldManager->getFieldStorageDefinitions('node')[$field] ?? NULL;
    if (!$storage_def instanceof FieldStorageConfigInterface) {
      return [$bundle, FALSE];
    }
    foreach (array_values(array_unique($storage_def->getBundles())) as $candidate) {
      $defs = $this->entityFieldManager->getFieldDefinitions('node', $candidate);
      if (isset($defs[$field])) {
        return [$candidate, TRUE];
      }
    }
    return [$bundle, FALSE];
  }

  /**
   * @return array{variation_ids: int[], variation_orders: array<int, int[]>}
   */
  private function loadCompletedBookedVariationOrderMap(int $uid): array {
    if ($uid <= 0) {
      return ['variation_ids' => [], 'variation_orders' => []];
    }
    $order_ids = $this->entityTypeManager->getStorage('commerce_order')->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->condition('state', 'completed')
      ->execute();
    if (!$order_ids) {
      return ['variation_ids' => [], 'variation_orders' => []];
    }

    $orders = $this->entityTypeManager->getStorage('commerce_order')->loadMultiple(array_values($order_ids));
    $variation_orders = [];
    foreach ($orders as $order) {
      if (!$order instanceof OrderInterface) {
        continue;
      }
      foreach ($order->getItems() as $item) {
        $purchased = $item->getPurchasedEntity();
        if ($purchased instanceof ProductVariationInterface) {
          $variation_id = (int) $purchased->id();
          $order_id = (int) $order->id();
          $variation_orders[$variation_id][$order_id] = $order_id;
        }
      }
    }

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
   * @param array<int, int[]> $variation_orders
   * @return int[]
   */
  private function orderIdsForEventNode(NodeInterface $node, string $variation_field, array $variation_orders): array {
    if (!$node->hasField($variation_field) || $node->get($variation_field)->isEmpty()) {
      return [];
    }
    $order_ids = [];
    foreach ($node->get($variation_field) as $item) {
      $variation_id = (int) ($item->target_id ?? 0);
      if ($variation_id <= 0 || empty($variation_orders[$variation_id])) {
        continue;
      }
      foreach ($variation_orders[$variation_id] as $order_id) {
        $order_ids[(int) $order_id] = (int) $order_id;
      }
    }
    rsort($order_ids, SORT_NUMERIC);
    return array_values($order_ids);
  }

  /**
   * @return array<string, mixed>
   */
  private function serializeCart(OrderInterface $order): array {
    $checkout_step = $order->get('checkout_step')->value;
    $items = [];
    foreach ($order->getItems() as $item) {
      $items[] = [
        'order_item_id' => (int) $item->id(),
        'title' => $item->getTitle(),
        'quantity' => (string) $item->getQuantity(),
      ];
    }
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
   * @return array{status: int, data: array}|null NULL when stock check passes.
   */
  private function evaluateTicketStock(
    ProductVariationInterface $variation,
    StoreInterface $store,
    AccountInterface $account,
    OrderInterface $cart,
    int $requested_quantity,
  ): ?array {
    $available_total = $this->resolveAvailableStockTotal($variation, $store, $account);
    if ($available_total === NULL) {
      return NULL;
    }
    $in_cart = $this->countVariationQuantityInCart($cart, (int) $variation->id());
    $remaining = max(0.0, (float) $available_total - $in_cart);

    if ($requested_quantity <= $remaining + 1e-6) {
      return NULL;
    }

    $payload = [
      'error' => 'insufficient_stock',
      'available_quantity' => $available_total,
      'in_cart_quantity' => $in_cart,
      'requested_quantity' => $requested_quantity,
      'remaining_quantity' => $remaining,
    ];
    $payload['message'] = ($available_total <= 0 || $remaining <= 0)
      ? (string) $this->t('This ticket is sold out or no longer available in stock.')
      : (string) $this->t('Only @count more ticket(s) can be added (@available in stock, @in_cart already in your cart for this ticket).', [
        '@count' => $this->formatQuantityForMessage($remaining),
        '@available' => (string) $available_total,
        '@in_cart' => $this->formatQuantityForMessage($in_cart),
      ]);

    return ['status' => 409, 'data' => $payload];
  }

  /**
   * @return int|null Non-negative stock level, or NULL when stock is not enforced.
   */
  private function resolveAvailableStockTotal(
    ProductVariationInterface $variation,
    StoreInterface $store,
    AccountInterface $account,
  ): ?int {
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

    if ($service->getId() === 'always_in_stock') {
      return NULL;
    }

    $checker = $service->getStockChecker();
    try {
      if ($checker->getIsAlwaysInStock($variation)) {
        return NULL;
      }
    }
    catch (\Throwable $e) {
      $this->logger->notice('event_booking stock: always-in-stock check skipped: @msg', ['@msg' => $e->getMessage()]);
    }

    try {
      $context = new Context($account, $store);
      $locations = $service->getConfiguration()->getAvailabilityLocations($context, $variation);
      $level = $checker->getTotalAvailableStockLevel($variation, $locations);
    }
    catch (\Throwable $e) {
      $this->logger->error('event_booking stock read failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }

    return is_numeric($level) ? max(0, (int) $level) : NULL;
  }

  private function loadEventStore(): ?StoreInterface {
    $id = trim((string) $this->configFactory->get('event_booking.settings')->get('commerce_store_id'));
    if ($id === '') {
      return NULL;
    }
    $store = $this->entityTypeManager->getStorage('commerce_store')->load($id);
    return $store instanceof StoreInterface ? $store : NULL;
  }

  private function variationBelongsToStore(ProductVariationInterface $variation, StoreInterface $store): bool {
    $product = $variation->getProduct();
    if (!$product) {
      return FALSE;
    }
    foreach ($product->getStores() as $s) {
      if ((int) $s->id() === (int) $store->id()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function getAccountEmail(AccountInterface $account): string {
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if ($user instanceof UserInterface) {
      return trim((string) $user->getEmail());
    }
    return trim((string) $account->getEmail());
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function extractPortalEmail(array $payload, string $portal_user_id): ?string {
    if (!empty($payload['emailId']) && is_string($payload['emailId'])) {
      try {
        $decrypted = $this->globalVariables->decrypt($payload['emailId']);
        if (is_string($decrypted) && $decrypted !== '') {
          return $decrypted;
        }
      }
      catch (\Throwable) {
        // Fall through to plain email field.
      }
    }
    if (!empty($payload['email']) && is_string($payload['email'])) {
      return $payload['email'];
    }
    if (str_contains($portal_user_id, '@')) {
      return $portal_user_id;
    }
    return NULL;
  }

  /**
   * @return array<string, mixed>
   */
  private function serializeEventNode(NodeInterface $node, string $date_field, string $image_field, string $location_field): array {
    $out = [
      'nid' => (int) $node->id(),
      'title' => $node->getTitle(),
    ];

    if ($node->hasField($date_field) && !$node->get($date_field)->isEmpty()) {
      $dr = $node->get($date_field)->first();
      if ($dr) {
        $val = $dr->getValue();
        $out['event_schedule'] = [
          'start' => isset($val['value']) ? $this->formatUtcStringToSiteIso((string) $val['value']) : NULL,
          'end' => isset($val['end_value']) ? $this->formatUtcStringToSiteIso((string) $val['end_value']) : NULL,
        ];
      }
    }

    if ($node->hasField($location_field) && !$node->get($location_field)->isEmpty()) {
      $out['location'] = $node->get($location_field)->value;
    }

    $out['image'] = $this->buildImageData($node, $image_field);
    $out['fields'] = $this->serializeNodeFields($node);
    return $out;
  }

  /**
   * @return array{url: string, alt: string}|null
   */
  private function buildImageData(NodeInterface $node, string $image_field): ?array {
    if (!$node->hasField($image_field) || $node->get($image_field)->isEmpty()) {
      return NULL;
    }
    $img_item = $node->get($image_field)->first();
    $file = $img_item && $img_item->entity instanceof FileInterface ? $img_item->entity : NULL;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $vals = $img_item ? $img_item->getValue() : [];
    return [
      'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
      'alt' => (string) ($vals['alt'] ?? ''),
    ];
  }

  /**
   * @return array<string, array<int, array<string, mixed>>>
   */
  private function serializeNodeFields(NodeInterface $node): array {
    $fields = [];
    foreach ($node->getFields() as $field_name => $items) {
      $definition = $items->getFieldDefinition();
      $storage_definition = $definition->getFieldStorageDefinition();
      if ($storage_definition->isBaseField() || $this->isSkippedNodeField($field_name)) {
        continue;
      }
      $field_type = (string) $definition->getType();
      $values = [];
      foreach ($items as $item) {
        $value = $item->getValue();
        $value = $this->normalizeFieldDateValuesToSiteTimezone($value, $field_type);
        if (isset($item->entity) && $item->entity instanceof FileInterface) {
          $value['url'] = $this->fileUrlGenerator->generateAbsoluteString($item->entity->getFileUri());
        }
        $values[] = $value;
      }
      $fields[$field_name] = $values;
    }
    ksort($fields);
    return $fields;
  }

}
