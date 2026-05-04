<?php

namespace Drupal\razorpay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles IPN requests from payment gateways.
 */
class IPNController extends ControllerBase
{

  public function __construct(protected $paymentGatewayManager) {}

  public static function create(ContainerInterface $container): static
  {
    return new static($container->get('plugin.manager.commerce_payment_gateway'));
  }

  /**
   * Handles the IPN request.
   */
  public function handleIPN(Request $request): Response
  {
    $response = NULL;

    $plugin_id = (string) $request->request->get('plugin_id', '');

    if ($plugin_id === '') {
      $response = new Response('Missing plugin_id', 400);
    } else {
      try {
        $payment_gateway = $this->paymentGatewayManager->createInstance($plugin_id);
        $verified = $payment_gateway->onNotify($request);

        if ($verified instanceof Response) {
          $response = $verified;
        } elseif ($verified === FALSE) {
          $response = new Response('IPN message verification failed.', 400);
        } else {
          $response = new Response('IPN processed.', 200);
        }
      } catch (\Throwable $exception) {
        $this->getLogger('razorpay')->error($exception->getMessage());
        $response = new Response('IPN processing failed.', 500);
      }
    }

    return $response;
  }
}
