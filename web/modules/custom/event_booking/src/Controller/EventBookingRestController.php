<?php

namespace Drupal\event_booking\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\court_booking\CommerceCheckoutRestService;
use Drupal\event_booking\EventBookingApiService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * REST endpoints for event ticket booking.
 */
final class EventBookingRestController extends ControllerBase {

  /**
   * Constructs an EventBookingRestController.
   *
   * @param \Drupal\event_booking\EventBookingApiService $api
   *   Event booking API service containing the business logic.
   * @param \Drupal\court_booking\CommerceCheckoutRestService $commerceCheckoutRest
   *   Commerce checkout REST service proxied for payment and cancel endpoints.
   */
  public function __construct(
    protected EventBookingApiService $api,
    protected CommerceCheckoutRestService $commerceCheckoutRest,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('event_booking.api'),
      $container->get('court_booking.commerce_checkout_rest'),
    );
  }

  /**
   * Verifies portal user id against portal API and Drupal bearer identity.
   */
  public function verifyPortalUser(Request $request): JsonResponse {
    $data = $this->decodeJson($request);
    $portal_user_id = trim((string) ($data['portal_user_id'] ?? ''));
    if ($portal_user_id === '') {
      throw new BadRequestHttpException('Missing portal_user_id.');
    }
    $result = $this->api->verifyPortalUser($this->currentUser(), $portal_user_id);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Returns portal user id for the Bearer user (same portal call as ApiRedirectSubscriber).
   */
  public function portalUserContext(): JsonResponse {
    $result = $this->api->resolvePortalUserContext($this->currentUser());
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Returns Commerce price fields for a ticket variation in the event store.
   */
  public function ticketVariationPricing(ProductVariationInterface $commerce_product_variation): JsonResponse {
    $result = $this->api->getTicketVariationPricing($this->currentUser(), $commerce_product_variation);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Adds event tickets to the configured Event Store cart.
   */
  public function addCartItems(Request $request): JsonResponse {
    $data = $this->decodeJson($request);
    $result = $this->api->addTickets($this->currentUser(), $data);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Returns current user's event store cart summary.
   */
  public function getCart(): JsonResponse {
    $result = $this->api->getCartPayload($this->currentUser());
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Clears all items from current user's event cart.
   */
  public function clearCart(): JsonResponse {
    $result = $this->api->clearCart($this->currentUser());
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Proxies to court_booking payment details for example_payment flow.
   */
  public function paymentDetails(OrderInterface $commerce_order, Request $request): JsonResponse {
    $data = $this->decodeJson($request);
    $result = $this->commerceCheckoutRest->collectPaymentDetails($commerce_order, $this->currentUser(), $data);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Proxies to court_booking payment confirm for example_payment flow.
   */
  public function paymentConfirm(OrderInterface $commerce_order, Request $request): JsonResponse {
    $data = $this->decodeJson($request);
    $result = $this->commerceCheckoutRest->confirmPayment($commerce_order, $this->currentUser(), $data);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Order receipt with event details after payment.
   */
  public function receipt(OrderInterface $commerce_order): JsonResponse {
    $result = $this->api->buildReceipt($commerce_order, $this->currentUser());
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Cancels user's own placed/completed event order (no auto-refund).
   */
  public function cancelOrder(OrderInterface $commerce_order, Request $request): JsonResponse {
    $data = $this->decodeJson($request);
    $result = $this->commerceCheckoutRest->cancelOrder($commerce_order, $this->currentUser(), $data);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Returns authenticated user's upcoming booked events list.
   */
  public function myBookedUpcomingEvents(Request $request): JsonResponse {
    $result = $this->api->getMyBookedEvents($this->currentUser(), 'upcoming', $this->extractListParams($request));
    return $this->privateNoStoreJsonResponse($result['data'], (int) $result['status']);
  }

  /**
   * Returns authenticated user's completed booked events list.
   */
  public function myBookedCompletedEvents(Request $request): JsonResponse {
    $result = $this->api->getMyBookedEvents($this->currentUser(), 'completed', $this->extractListParams($request));
    return $this->privateNoStoreJsonResponse($result['data'], (int) $result['status']);
  }

  /**
   * Returns unified segmented bookings payload for mobile clients.
   */
  public function myUnifiedBookings(Request $request): JsonResponse {
    $result = $this->api->getUnifiedBookings($this->currentUser(), $this->extractUnifiedListParams($request));
    return $this->privateNoStoreJsonResponse($result['data'], (int) $result['status']);
  }

  /**
   * Builds a private, non-cacheable JSON response for user-scoped booking data.
   *
   * @param mixed $data
   *   JSON-serializable payload.
   * @param int $status
   *   HTTP status code.
   */
  private function privateNoStoreJsonResponse(mixed $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    $response->headers->set('Vary', 'Authorization, Cookie');
    return $response;
  }

  /**
   * Decodes a JSON request body into an associative array.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   *
   * @return array<string, mixed>
   *   Decoded body as an associative array; empty array for an empty body.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the body is non-empty but not valid JSON, or not an object/array.
   */
  private function decodeJson(Request $request): array {
    $raw = $request->getContent();
    $data = $raw !== '' ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    return $data;
  }

  /**
   * Extracts standard list pagination parameters from a request query string.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   *
   * @return array{page: int, limit: int, q: string}
   *   Normalised pagination parameters with a clamped limit (1–50).
   */
  private function extractListParams(Request $request): array {
    $page = max(0, (int) $request->query->get('page', 0));
    $limit = $this->clampPaginationLimit((int) $request->query->get('limit', 10), 10, 50);
    $q = trim((string) $request->query->get('q', ''));
    return ['page' => $page, 'limit' => $limit, 'q' => $q];
  }

  /**
   * Extracts unified bookings list parameters from a request query string.
   *
   * Reads per-segment page and limit values for court and event bookings,
   * along with shared filters (bucket, kind, q, sport_tid).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   *
   * @return array<string, int|string>
   *   Normalised parameters for getUnifiedBookings().
   */
  private function extractUnifiedListParams(Request $request): array {
    $bucket = mb_strtolower(trim((string) $request->query->get('bucket', 'upcoming')));
    if (!in_array($bucket, ['upcoming', 'past'], TRUE)) {
      $bucket = 'upcoming';
    }
    $kind = mb_strtolower(trim((string) $request->query->get('kind', 'all')));
    if (!in_array($kind, ['all', 'court', 'event'], TRUE)) {
      $kind = 'all';
    }
    $q = trim((string) $request->query->get('q', ''));
    $sport_tid = max(0, (int) $request->query->get('sport_tid', 0));

    $court_page = max(0, (int) $request->query->get('court_page', 0));
    $court_limit = $this->clampPaginationLimit((int) $request->query->get('court_limit', 10), 10, 50);

    $event_page = max(0, (int) $request->query->get('event_page', 0));
    $event_limit = $this->clampPaginationLimit((int) $request->query->get('event_limit', 10), 10, 50);

    return [
      'bucket' => $bucket,
      'kind' => $kind,
      'q' => $q,
      'sport_tid' => $sport_tid,
      'court_page' => $court_page,
      'court_limit' => $court_limit,
      'event_page' => $event_page,
      'event_limit' => $event_limit,
    ];
  }

  /**
   * Clamps a raw pagination limit to a valid range, using a default fallback.
   *
   * @param int $raw
   *   The raw integer value from the query string.
   * @param int $default
   *   Value to use when $raw is ≤ 0.
   * @param int $max
   *   Maximum permitted value.
   *
   * @return int
   *   A limit value in the range [1, $max].
   */
  private function clampPaginationLimit(int $raw, int $default, int $max): int {
    return min($max, max(1, $raw > 0 ? $raw : $default));
  }

}
