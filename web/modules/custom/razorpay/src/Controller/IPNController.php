<?php

namespace Drupal\razorpay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles IPN requests from payment gateways.
 */
class IPNController extends ControllerBase {

  public function __construct(protected $paymentGatewayManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('plugin.manager.commerce_payment_gateway'));
  }

  /**
   * Handles the IPN request.
   */
  public function handleIPN(Request $request): Response {
    $plugin_id = (string) $request->request->get('plugin_id', '');
    if ($plugin_id === '') {
      return new Response('Missing plugin_id', 400);
    }

    try {
      $payment_gateway = $this->paymentGatewayManager->createInstance($plugin_id);
      $verified = $payment_gateway->onNotify($request);
      if ($verified instanceof Response) {
        return $verified;
      }
      if ($verified === FALSE) {
        return new Response('IPN message verification failed.', 400);
      }
      return new Response('IPN processed.', 200);
    }
    catch (\Throwable $exception) {
      $this->getLogger('razorpay')->error($exception->getMessage());
      return new Response('IPN processing failed.', 500);
    }
  }

}

