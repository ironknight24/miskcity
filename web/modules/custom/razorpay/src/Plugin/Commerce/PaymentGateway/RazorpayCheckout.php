<?php

namespace Drupal\razorpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\razorpay\AutoWebhook;
use Psr\Log\LoggerInterface;
use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RazorpayCheckout extends OffsitePaymentGatewayBase implements RazorpayInterface
{

  public const PAYMENT_AUTHORIZED = 'payment.authorized';
  public const PAYMENT_FAILED = 'payment.failed';
  public const REFUNDED_CREATED = 'refund.created';

  protected const WEBHOOK_NOTIFY_WAIT_TIME = 180;
  protected const HTTP_CONFLICT_STATUS = 409;

  protected ?AutoWebhook $autoWebhook = NULL;
  protected ?LoggerInterface $logger = NULL;
  protected ?ConfigFactoryInterface $configFactory = NULL;

  protected function resolvePaymentGatewayId(OrderInterface $order): ?string
  {
    $gatewayId = NULL;

    if ($order->hasField('payment_gateway')) {
      $gatewayId = $order->get('payment_gateway')->target_id ?: NULL;
    }

    return $gatewayId ?? (method_exists($this, 'getEntityId') ? $this->getEntityId() : NULL);
  }

  public function defaultConfiguration(): array
  {
    return [
      'key_id' => '',
      'key_secret' => '',
      'payment_action' => 'capture',
    ] + parent::defaultConfiguration();
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static
  {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->autoWebhook = $container->get('razorpay.auto_webhook');
    $instance->logger = $container->get('logger.channel.razorpay');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  public function onNotify(Request $request): ?Response
  {
    $response = NULL;

    $data = $this->getWebhookData($request);

    if ($data === NULL) {
      $response = NULL;
    } else {
      $order = $this->loadWebhookOrder($data);

      if (!$order) {
        $response = new Response('Order not found', 404);
      } else {
        $conflict = $this->checkWebhookConflict($order);

        if ($conflict) {
          $response = $conflict;
        } else {
          $verificationError = $this->verifyWebhook($request);

          if ($verificationError) {
            $response = $verificationError;
          } else {
            $response = $this->handleWebhookEvent($data, $order);
          }
        }
      }
    }

    return $response;
  }

  private function getWebhookData(Request $request): ?array
  {
    $supported = [
      self::PAYMENT_AUTHORIZED,
      self::REFUNDED_CREATED,
      self::PAYMENT_FAILED,
    ];

    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['event']) || !in_array($data['event'], $supported, TRUE)) {
      return NULL;
    }

    return $data;
  }

  private function loadWebhookOrder(array $data): ?OrderInterface
  {
    $orderId = (int) ($data['payload']['payment']['entity']['notes']['drupal_order_id'] ?? 0);
    return $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
  }

  private function checkWebhookConflict(OrderInterface $order): ?Response
  {
    $notified = $order->getData('rzp_webhook_notified_at');

    if (empty($notified) || (time() - (int) $notified) < static::WEBHOOK_NOTIFY_WAIT_TIME) {
      $order->setData('rzp_webhook_notified_at', time())->save();
      return new Response('Webhook conflicts due to early execution.', static::HTTP_CONFLICT_STATUS);
    }

    return NULL;
  }

  private function verifyWebhook(Request $request): ?Response
  {
    $api = $this->getRazorpayApiInstance();
    $signature = $request->headers->get('X-Razorpay-Signature');

    $config = $this->configFactory?->get('razorpay.settings');
    $secret = (string) ($config?->get('razorpay_flags.webhook_secret') ?? '');

    try {
      $api->utility->verifyWebhookSignature($request->getContent(), $signature, $secret);
    } catch (\Throwable $e) {
      $this->logger?->error($e->getMessage());
      return new Response($e->getMessage(), 401);
    }

    return NULL;
  }

  private function handleWebhookEvent(array $data, OrderInterface $order): Response
  {
    $response = NULL;
    $event = $data['event'];

    switch ($event) {
      case self::PAYMENT_AUTHORIZED:
        $response = $this->handleAuthorized($data, $order);
        break;

      case self::PAYMENT_FAILED:
        $this->logger?->info('Payment failed for order ID: ' . $order->id());
        break;

      case self::REFUNDED_CREATED:
        $response = $this->handleRefund($data);
        break;

      default:
        $response = new Response('Unhandled event', 200);
        break;
    }

    if ($response === NULL) {
      $this->logger?->info('Webhook processed successfully for ' . $event);
      $response = new Response('Webhook processed successfully', 200);
    }

    return $response;
  }

  private function handleAuthorized(array $data, OrderInterface $order): Response
  {
    if ($order->getState()->getId() !== 'draft') {
      return new Response('order is in ' . $order->getState()->getId() . ' state', 200);
    }

    $paymentId = $data['payload']['payment']['entity']['id'];
    $api = $this->getRazorpayApiInstance();

    $razorpayPayment = $api->payment->fetch($paymentId);
    $state = $razorpayPayment['status'] === 'captured' ? 'completed' : 'authorization';

    $amount = Price::fromArray([
      'number' => ((string) (($data['payload']['payment']['entity']['amount'] ?? 0) / 100)),
      'currency_code' => $data['payload']['payment']['entity']['currency'],
    ]);

    $gatewayId = $this->resolvePaymentGatewayId($order);

    $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
      'state' => $state,
      'amount' => $amount,
      'payment_gateway' => $gatewayId,
      'order_id' => $order->id(),
      'remote_id' => $paymentId,
      'remote_state' => $data['payload']['payment']['entity']['status'],
      'authorized' => $this->time->getRequestTime(),
    ]);

    $payment->save();

    return new Response('Authorized processed', 200);
  }

  private function handleRefund(array $data): Response
  {
    $paymentId = $data['payload']['payment']['entity']['id'];

    $payments = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->loadByProperties(['remote_id' => $paymentId]);

    if (count($payments) !== 1) {
      return new Response('Payment not found or multiple payments found', 404);
    }

    $total = (($data['payload']['payment']['entity']['amount'] ?? 0) / 100);
    $refund = (($data['payload']['payment']['entity']['amount_refunded'] ?? 0) / 100);

    $payment = reset($payments);

    $payment->setState(((float) $total === (float) $refund) ? 'refunded' : 'partially_refunded');

    $refundAmount = new Price((string) $refund, $payment->getAmount()->getCurrencyCode());
    $payment->setRefundedAmount($refundAmount);
    $payment->save();

    return new Response('Refund processed', 200);
  }
}
