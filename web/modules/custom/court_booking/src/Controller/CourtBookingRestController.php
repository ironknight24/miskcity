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
   * Calendar JSON (same as commerce_bat.availability_json).
   */
  public function availability(ProductVariation $commerce_product_variation, Request $request) {
    return $this->availabilityController->calendar($commerce_product_variation, $request);
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
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->courtBookingApi->buildSlotCandidatesResponse($data, $this->currentUser());
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * POST add cart line.
   */
  public function addLineItem(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
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
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->courtBookingApi->updateCartLineSlot($this->currentUser(), $commerce_order_item, $data);
    return new JsonResponse($result['data'], $result['status']);
  }

}
