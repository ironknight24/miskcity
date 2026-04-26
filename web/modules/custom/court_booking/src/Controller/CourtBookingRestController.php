<?php

namespace Drupal\court_booking\Controller;

use Drupal\commerce_bat\Controller\AvailabilityController;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\court_booking\CourtBookingApiService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * OAuth2 JSON endpoints under /rest/v1/court-booking/.
 */
final class CourtBookingRestController extends ControllerBase {

  private const INVALID_JSON_BODY = 'Invalid JSON body.';

  public function __construct(
    protected CourtBookingApiService $courtBookingApi,
    protected AvailabilityController $availabilityController,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.api'),
      $container->get('class_resolver')->getInstanceFromDefinition(AvailabilityController::class),
    );
  }

  /**
   * Rule-aware availability slots.
   */
  public function availability(ProductVariation $commerce_product_variation, Request $request): JsonResponse {
    $result = $this->courtBookingApi->buildAvailabilityResponse($commerce_product_variation, $request, $this->currentUser());
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Single-range availability check (same as commerce_bat.availability_check).
   */
  public function slotCheck(ProductVariation $commerce_product_variation, Request $request): JsonResponse {
    return $this->availabilityController->checkRange($commerce_product_variation, $request);
  }

  /**
   * POST slot candidates.
   */
  public function slotCandidates(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException(self::INVALID_JSON_BODY);
    }
    $result = $this->courtBookingApi->buildSlotCandidatesResponse($data, $this->currentUser());
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Sports bootstrap payload for mobile clients.
   */
  public function sports(): JsonResponse {
    $result = $this->courtBookingApi->buildSportsBootstrapResponse($this->currentUser());
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * GET completed-order court bookings whose rental end is still in the future.
   */
  public function myBookingsUpcoming(Request $request): JsonResponse {
    $result = $this->courtBookingApi->buildMyBookingsResponse($this->currentUser(), 'upcoming', $this->extractListParams($request));
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * GET completed-order court bookings whose rental end is in the past.
   */
  public function myBookingsPast(Request $request): JsonResponse {
    $result = $this->courtBookingApi->buildMyBookingsResponse($this->currentUser(), 'past', $this->extractListParams($request));
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * POST add cart line.
   */
  public function addLineItem(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException(self::INVALID_JSON_BODY);
    }
    $result = $this->courtBookingApi->addBookingLineItem($this->currentUser(), $data, [
      'include_legacy_redirect' => FALSE,
      'include_rest_fields' => TRUE,
    ]);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * PATCH cart line slot.
   */
  public function patchLineItem(Request $request, OrderItemInterface $commerce_order_item): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException(self::INVALID_JSON_BODY);
    }
    $result = $this->courtBookingApi->updateCartLineSlot($this->currentUser(), $commerce_order_item, $data);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * POST cancel/remove cart line item (draft orders only).
   */
  public function deleteLineItem(OrderItemInterface $commerce_order_item): JsonResponse {
    $result = $this->courtBookingApi->cancelBookingLineItem($this->currentUser(), $commerce_order_item);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * POST clears all line items from the current user's court draft cart.
   */
  public function clearCart(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw !== '' ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException(self::INVALID_JSON_BODY);
    }
    $result = $this->courtBookingApi->clearCart($this->currentUser());
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * @return array{page: int, limit: int, q: string, sport_tid: int}
   */
  private function extractListParams(Request $request): array {
    $page = max(0, (int) $request->query->get('page', 0));
    $limit = (int) $request->query->get('limit', 10);
    if ($limit <= 0) {
      $limit = 10;
    }
    $limit = min($limit, 50);
    $q = trim((string) $request->query->get('q', ''));
    $sport_tid = max(0, (int) $request->query->get('sport_tid', 0));
    return [
      'page' => $page,
      'limit' => $limit,
      'q' => $q,
      'sport_tid' => $sport_tid,
    ];
  }

}
