<?php

namespace Drupal\razorpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;

/**
 * Defines the Razorpay payment gateway plugin contract.
 */
interface RazorpayInterface {

  /**
   * Captures a previously-authorized payment.
   */
  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL): void;

  /**
   * Refunds a payment, fully or partially.
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL): void;

}

