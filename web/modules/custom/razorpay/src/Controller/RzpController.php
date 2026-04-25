<?php

namespace Drupal\razorpay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Razorpay utility controller.
 */
class RzpController extends ControllerBase {

  /**
   * Redirect helper endpoint.
   */
  public function capturePayment(): RedirectResponse {
    $url = Url::fromRoute('commerce_payment.checkout.return', [
      'commerce_order' => '1',
      'step' => 'payment',
    ], ['absolute' => TRUE])->toString();

    return new RedirectResponse($url);
  }

}

