<?php

namespace Drupal\court_booking\Controller;

use Drupal\court_booking\CommerceCheckoutRestService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Webhook endpoint for async payment status callbacks.
 */
final class PaymentWebhookRestController extends ControllerBase {

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
   * Receives gateway payment status callback payloads.
   */
  public function receive(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->commerceCheckoutRest->processPaymentWebhook($request, $data);
    return new JsonResponse($result['data'], $result['status']);
  }

}
