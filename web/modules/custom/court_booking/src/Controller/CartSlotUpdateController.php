<?php

namespace Drupal\court_booking\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\court_booking\CourtBookingApiService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Updates BAT rental slot on a cart order item (JSON API).
 */
final class CartSlotUpdateController extends ControllerBase {

  public function __construct(
    protected CourtBookingApiService $courtBookingApi,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.api'),
    );
  }

  /**
   * POST JSON body: { "start": "...", "end": "..." } (UTC ISO, same as add).
   */
  public function updateSlot(Request $request, OrderItemInterface $commerce_order_item): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->courtBookingApi->updateCartLineSlot($this->currentUser(), $commerce_order_item, $data);
    return new JsonResponse($result['data'], $result['status']);
  }

}
