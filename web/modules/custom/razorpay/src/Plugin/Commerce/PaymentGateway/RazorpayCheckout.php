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

/**
 * Provides the Razorpay offsite Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "razorpay",
 *   label = @Translation("Razorpay"),
 *   display_label = @Translation("Razorpay"),
 *   forms = {
 *     "offsite-payment" = "Drupal\razorpay\PluginForm\RazorpayForm",
 *   }
 * )
 */
class RazorpayCheckout extends OffsitePaymentGatewayBase implements RazorpayInterface {

  public const PAYMENT_AUTHORIZED = 'payment.authorized';
  public const PAYMENT_FAILED = 'payment.failed';
  public const REFUNDED_CREATED = 'refund.created';
  protected const WEBHOOK_NOTIFY_WAIT_TIME = 180;
  protected const HTTP_CONFLICT_STATUS = 409;

  protected ?AutoWebhook $autoWebhook = NULL;
  protected ?LoggerInterface $logger = NULL;
  protected ?ConfigFactoryInterface $configFactory = NULL;

  protected function resolvePaymentGatewayId(OrderInterface $order): ?string {
    $gatewayId = NULL;
    if ($order->hasField('payment_gateway')) {
      $gatewayId = $order->get('payment_gateway')->target_id ?: NULL;
    }
    return $gatewayId ?: (method_exists($this, 'getEntityId') ? $this->getEntityId() : NULL);
  }

  public function defaultConfiguration(): array {
    return [
      'key_id' => '',
      'key_secret' => '',
      'payment_action' => 'capture',
    ] + parent::defaultConfiguration();
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->autoWebhook = $container->get('razorpay.auto_webhook');
    $instance->logger = $container->get('logger.channel.razorpay');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['display_label']['#prefix'] = 'First <a href="https://easy.razorpay.com/onboarding?recommended_product=payment_gateway&source=drupal" target="_blank">signup</a> for a Razorpay account or <a href="https://dashboard.razorpay.com/signin?screen=sign_in&source=drupal" target="_blank">login</a> if you have an existing account.</p>';
    $form['key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key ID'),
      '#description' => $this->t('The key ID and key secret can be generated from API Keys in Razorpay Dashboard.'),
      '#default_value' => $this->configuration['key_id'],
      '#required' => TRUE,
    ];
    $form['key_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key Secret'),
      '#description' => $this->t('The key ID and key secret can be generated from API Keys in Razorpay Dashboard.'),
      '#default_value' => $this->configuration['key_secret'],
      '#required' => TRUE,
    ];
    $form['payment_action'] = [
      '#type' => 'select',
      '#title' => $this->t('Payment Action'),
      '#options' => [
        'capture' => $this->t('Authorize and Capture'),
        'authorize' => $this->t('Authorize'),
      ],
      '#default_value' => $this->configuration['payment_action'],
    ];
    return $form;
  }

  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $form_state->setValue('id', 'razorpay');
    $values = $form_state->getValue($form['#parents']);
    if (empty($values['key_id']) || empty($values['key_secret'])) {
      return;
    }
    if (substr($values['key_id'], 0, 8) !== 'rzp_' . $values['mode']) {
      $this->messenger()->addError($this->t('Invalid Key ID or Key Secret for @mode mode.', ['@mode' => $values['mode']]));
      $form_state->setError($form['mode']);
      return;
    }
    try {
      $api = new Api($values['key_id'], $values['key_secret']);
      $api->order->all(['count' => 1]);
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('Invalid Key ID or Key Secret.'));
      $form_state->setError($form['key_id']);
      $form_state->setError($form['key_secret']);
    }
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['key_id'] = $values['key_id'];
    $this->configuration['key_secret'] = $values['key_secret'];
    $this->configuration['payment_action'] = $values['payment_action'];
    if ($this->autoWebhook) {
      $this->autoWebhook->autoEnableWebhook($values['key_id'], $values['key_secret']);
    }
  }

  public function onReturn(OrderInterface $order, Request $request): void {
    try {
      $api = $this->getRazorpayApiInstance();
      $attributes = [
        'razorpay_order_id' => $request->get('razorpay_order_id'),
        'razorpay_payment_id' => $request->get('razorpay_payment_id'),
        'razorpay_signature' => $request->get('razorpay_signature'),
      ];
      $api->utility->verifyPaymentSignature($attributes);
      $orderObject = $api->order->fetch((string) $order->getData('razorpay_order_id'));
      $paymentObject = $orderObject->payments();
      $status = end($paymentObject['items'])->status;
      $message = '';
      $remoteStatus = '';
      $requestTime = $this->time->getRequestTime();
      if ($status === 'captured') {
        $remoteStatus = (string) $this->t('Completed');
        $message = (string) $this->t('Your payment was successful with Order id : @orderid at : @date', ['@orderid' => $order->id(), '@date' => date('d-m-Y H:i:s', $requestTime)]);
        $status = 'completed';
      }
      elseif ($status === 'authorized') {
        $remoteStatus = (string) $this->t('Pending');
        $message = (string) $this->t('Your payment with Order id : @orderid is pending at : @date', ['@orderid' => $order->id(), '@date' => date('d-m-Y H:i:s', $requestTime)]);
        $status = 'authorization';
      }
      elseif ($status === 'failed') {
        $message = (string) $this->t('Your payment with Order id : @orderid failed at : @date', ['@orderid' => $order->id(), '@date' => date('d-m-Y H:i:s', $requestTime)]);
        $this->logger?->error($message);
        throw new PaymentGatewayException($message);
      }
      $gatewayId = $this->resolvePaymentGatewayId($order);
      $paymentItem = end($paymentObject['items']);
      $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
        'state' => $status,
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $gatewayId,
        'order_id' => $order->id(),
        'test' => $this->getMode() === 'test',
        'remote_id' => $paymentItem->id,
        'remote_state' => $remoteStatus ?: $request->get('payment_status'),
        'authorized' => $requestTime,
      ]);
      $payment->save();
      $this->messenger()->addMessage($message);
    }
    catch (SignatureVerificationError $exception) {
      $this->logger?->error($exception->getMessage());
      throw new PaymentGatewayException('Your payment to Razorpay failed: ' . $exception->getMessage());
    }
    catch (\Throwable $exception) {
      $this->logger?->error($exception->getMessage());
      throw new PaymentGatewayException($exception->getMessage());
    }
  }

  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['authorization']);
    $amount = $amount ?: $payment->getAmount();
    try {
      $api = $this->getRazorpayApiInstance();
      $razorpayPayment = $api->payment->fetch($payment->getRemoteId());
      $razorpayPayment->capture([
        'amount' => Calculator::trim($amount) * 100,
        'currency' => $amount->getCurrencyCode(),
      ]);
    }
    catch (\Throwable $exception) {
      $this->logger?->error($exception->getMessage());
      throw new PaymentGatewayException($exception->getMessage());
    }
    $payment->setState('completed');
    $payment->setAmount($amount);
    $payment->save();
  }

  public function voidPayment(PaymentInterface $payment): void {
    throw new PaymentGatewayException('Void payments are not supported, please click cancel.');
  }

  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);
    try {
      $api = $this->getRazorpayApiInstance();
      $razorpayPayment = $api->payment->fetch($payment->getRemoteId());
      $razorpayPayment->refund(['amount' => Calculator::trim($amount) * 100]);
    }
    catch (\Throwable $exception) {
      $this->logger?->error($exception->getMessage());
      throw new PaymentGatewayException($exception->getMessage());
    }
    $oldRefundedAmount = $payment->getRefundedAmount();
    $newRefundedAmount = $oldRefundedAmount->add($amount);
    $payment->setState($newRefundedAmount->lessThan($payment->getAmount()) ? 'partially_refunded' : 'refunded');
    $payment->setRefundedAmount($newRefundedAmount);
    $payment->save();
  }

  protected function getRazorpayApiInstance(?string $key = NULL, ?string $secret = NULL): Api {
    $key = $key ?? $this->configuration['key_id'];
    $secret = $secret ?? $this->configuration['key_secret'];
    return new Api($key, $secret);
  }

  public function onCancel(OrderInterface $order, Request $request): void {
    $this->messenger()->addMessage($this->t('You have canceled checkout at @gateway but may resume checkout when ready.', ['@gateway' => $this->getDisplayLabel()]));
  }

  public function onNotify(Request $request): ?Response {
    $supportedWebhookEvents = [self::PAYMENT_AUTHORIZED, self::REFUNDED_CREATED, self::PAYMENT_FAILED];
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data['event']) || !in_array($data['event'], $supportedWebhookEvents, TRUE)) {
      return NULL;
    }

    $orderId = (int) ($data['payload']['payment']['entity']['notes']['drupal_order_id'] ?? 0);
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    if (!$order) {
      return new Response('Order not found', 404);
    }
    $rzpWebhookNotifiedAt = $order->getData('rzp_webhook_notified_at');
    if (empty($rzpWebhookNotifiedAt) || (time() - (int) $rzpWebhookNotifiedAt) < static::WEBHOOK_NOTIFY_WAIT_TIME) {
      $order->setData('rzp_webhook_notified_at', time())->save();
      return new Response('Webhook conflicts due to early execution.', static::HTTP_CONFLICT_STATUS);
    }

    $api = $this->getRazorpayApiInstance();
    $signature = $request->headers->get('X-Razorpay-Signature');
    $config = $this->configFactory?->get('razorpay.settings');
    $webhook_secret = (string) ($config?->get('razorpay_flags.webhook_secret') ?? '');
    try {
      $api->utility->verifyWebhookSignature($request->getContent(), $signature, $webhook_secret);
    }
    catch (\Throwable $exception) {
      $this->logger?->error($exception->getMessage());
      return new Response($exception->getMessage(), 401);
    }

    $event = $data['event'];
    $paymentId = $data['payload']['payment']['entity']['id'];
    switch ($event) {
      case self::PAYMENT_AUTHORIZED:
        if ($order->getState()->getId() !== 'draft') {
          return new Response('order is in ' . $order->getState()->getId() . ' state', 200);
        }
        $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
        $razorpayPayment = $api->payment->fetch($paymentId);
        $state = $razorpayPayment['status'] === 'captured' ? 'completed' : 'authorization';
        $amount = Price::fromArray([
          'number' => ((string) (($data['payload']['payment']['entity']['amount'] ?? 0) / 100)),
          'currency_code' => $data['payload']['payment']['entity']['currency'],
        ]);
        $gatewayId = $this->resolvePaymentGatewayId($order);
        $payment = $paymentStorage->create([
          'state' => $state,
          'amount' => $amount,
          'payment_gateway' => $gatewayId,
          'order_id' => $orderId,
          'remote_id' => $paymentId,
          'remote_state' => $data['payload']['payment']['entity']['status'],
          'authorized' => $this->time->getRequestTime(),
        ]);
        $payment->save();
        break;

      case self::PAYMENT_FAILED:
        $this->logger?->info('Payment failed for order ID: ' . $orderId);
        break;

      case self::REFUNDED_CREATED:
        $payments = $this->entityTypeManager->getStorage('commerce_payment')->loadByProperties(['remote_id' => $paymentId]);
        if (count($payments) !== 1) {
          return new Response('Payment not found or multiple payments found', 404);
        }
        $totalamt = (($data['payload']['payment']['entity']['amount'] ?? 0) / 100);
        $amtRefund = (($data['payload']['payment']['entity']['amount_refunded'] ?? 0) / 100);
        $payment = reset($payments);
        $payment->setState(((float) $totalamt === (float) $amtRefund) ? 'refunded' : 'partially_refunded');
        $refund_amount = new Price((string) $amtRefund, $payment->getAmount()->getCurrencyCode());
        $payment->setRefundedAmount($refund_amount);
        $payment->save();
        break;
    }

    $this->logger?->info('Webhook processed successfully for ' . $event);
    return new Response('Webhook processed successfully', 200);
  }

}

