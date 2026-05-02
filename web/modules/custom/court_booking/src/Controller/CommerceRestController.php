<?php

namespace Drupal\court_booking\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\court_booking\CommerceCheckoutRestService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * OAuth2 Commerce cart / checkout helpers under /rest/v1/commerce/.
 */
final class CommerceRestController extends ControllerBase {

  public function __construct(
    protected CommerceCheckoutRestService $commerceCheckoutRest,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.commerce_checkout_rest'),
    );
  }

  /**
   * Current user's cart for court booking order type.
   */
  public function getCart(): JsonResponse {
    $order = $this->commerceCheckoutRest->getCartOrder($this->currentUser());
    if (!$order instanceof OrderInterface) {
      return new JsonResponse(['message' => (string) $this->t('No active cart.')], 404);
    }
    return new JsonResponse($this->commerceCheckoutRest->buildCartPayload($order));
  }

  /**
   * Complete checkout: manual payment or zero balance.
   */
  public function completeCheckout(OrderInterface $commerce_order, Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->commerceCheckoutRest->payAndPlaceOrder($commerce_order, $this->currentUser(), $data);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Collects payment details for API-first offsite checkout flow.
   */
  public function paymentDetails(OrderInterface $commerce_order, Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->commerceCheckoutRest->collectPaymentDetails($commerce_order, $this->currentUser(), $data);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Confirms payment and places order for API-first offsite checkout flow.
   */
  public function paymentConfirm(OrderInterface $commerce_order, Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->commerceCheckoutRest->confirmPayment($commerce_order, $this->currentUser(), $data);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Reports payment failure for an order.
   */
  public function paymentFailure(OrderInterface $commerce_order, Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->commerceCheckoutRest->reportPaymentFailure($commerce_order, $this->currentUser(), $data);
    return new JsonResponse($result['data'], $result['status']);
  }

  /**
   * Cancels user's own placed/completed order (no auto-refund).
   */
  public function cancelOrder(OrderInterface $commerce_order, Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->commerceCheckoutRest->cancelOrder($commerce_order, $this->currentUser(), $data);
    return new JsonResponse($result['data'], $result['status']);
  }

}
