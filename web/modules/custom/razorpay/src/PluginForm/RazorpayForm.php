<?php

namespace Drupal\razorpay\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\razorpay\AutoWebhook;
use Razorpay\Api\Api;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides the off-site payment form.
 */
class RazorpayForm extends BasePaymentOffsiteForm {

  protected $payment;
  protected array $config = [];
  protected const TWELVE_HOURS = 43200;

  protected ?AutoWebhook $autoWebhook = NULL;

  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->autoWebhook = $container->get('razorpay.auto_webhook');
    return $instance;
  }

  public function createOrGetRazorpayOrderId($order, array $orderData): string {
    $create = FALSE;
    $api = $this->getRazorpayApiInstance();
    try {
      $razorpayOrderId = $order->getData('razorpay_order_id');
      $razorpayOrderAmount = $order->getData('razorpay_order_amount');
      if (empty($razorpayOrderId) || empty($razorpayOrderAmount)) {
        $create = TRUE;
      }
      elseif (!empty($razorpayOrderId)) {
        $razorpayOrder = $api->order->fetch($razorpayOrderId);
        if ($razorpayOrder['amount'] !== $orderData['amount']) {
          $create = TRUE;
        }
        else {
          return $razorpayOrderId;
        }
      }
    }
    catch (\Throwable) {
      $create = TRUE;
    }

    if (!$create) {
      return '';
    }

    try {
      $orderPayload = [
        'receipt' => $order->id(),
        'amount' => (int) $orderData['amount'],
        'currency' => $orderData['currency'],
        'payment_capture' => ($orderData['payment_action'] === 'authorize') ? 0 : 1,
        'notes' => [
          'drupal_order_id' => (string) $order->id(),
        ],
      ];
      $razorpayOrder = $api->order->create($orderPayload);
      $order->setData('razorpay_order_id', $razorpayOrder->id);
      $order->setData('razorpay_order_amount', $razorpayOrder->amount);
      $order->save();
      return (string) $razorpayOrder['id'];
    }
    catch (\Throwable $exception) {
      \Drupal::logger('razorpay')->error($exception->getMessage());
      return 'error';
    }
  }

  protected function getRazorpayApiInstance(?string $key = NULL, ?string $secret = NULL): Api {
    $key = $key ?? $this->config['key_id'];
    $secret = $secret ?? $this->config['key_secret'];
    return new Api($key, $secret);
  }

  protected function setPaymentConfigAndMessenger(): void {
    $this->payment = $this->entity;
    $this->config = $this->payment->getPaymentGateway()->getPlugin()->getConfiguration();
  }

  public function generateCheckoutForm(array &$form, string $orderId, string $orderAmount): array {
    $html = '<p>ORDER NUMBER: <b>' . $orderId . '</b><br>ORDER TOTAL: <b>' . $orderAmount . '</b></p><hr><p>Thank you for your order, please click the button below to pay with Razorpay.</p><p id="msg-razorpay-success">Please wait while we are processing your payment.</p>';
    $form['pay_now'] = [
      '#type' => 'button',
      '#value' => $this->t('Pay Now'),
      '#prefix' => $html,
      '#attributes' => ['id' => 'btn-razorpay'],
    ];
    $form['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#attributes' => [
        'id' => 'btn-razorpay-cancel',
        'onclick' => 'window.location.replace("' . $form['#cancel_url'] . '");',
      ],
    ];
    return $form;
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $this->setPaymentConfigAndMessenger();
    $route_order = \Drupal::routeMatch()->getParameter('commerce_order');
    $orderId = $route_order?->id();
    $order = $this->payment->getOrder();
    $billing_profile = $order->getBillingProfile();
    $address_list = $billing_profile?->get('address');
    $address = $address_list?->first();
    $currency = $this->payment->getAmount()->getCurrencyCode();
    $orderAmount = $this->payment->getAmount()->getNumber() * 100;
    $orderData = [
      'amount' => $orderAmount,
      'currency' => $currency,
      'payment_action' => $this->config['payment_action'],
    ];
    $razorpayOrderId = $this->createOrGetRazorpayOrderId($order, $orderData);
    if ($razorpayOrderId === 'error') {
      $this->messenger()->addError($this->t('Unable to create Razorpay order.'));
      $url = Url::fromRoute('commerce_checkout.form', ['commerce_order' => $orderId, 'step' => 'review'], ['absolute' => TRUE])->toString();
      (new RedirectResponse($url))->send();
    }

    $callbackUrl = Url::fromRoute('commerce_payment.checkout.return', ['commerce_order' => $orderId, 'step' => 'payment'], ['absolute' => TRUE])->toString();
    $checkoutArgs = [
      'key' => $this->config['key_id'],
      'amount' => $orderAmount,
      'image' => 'https://cdn.razorpay.com/static/assets/logo/payment.svg',
      'order_id' => $razorpayOrderId,
      'name' => \Drupal::config('system.site')->get('name'),
      'currency' => $currency,
      'callback_url' => $callbackUrl,
      'prefill' => [
        'name' => $address ? ($address->getGivenName() . ' ' . $address->getFamilyName()) : '',
        'email' => $order->getEmail(),
        'contact' => '',
      ],
      'notes' => ['drupal_order_id' => $orderId],
    ];
    $this->generateCheckoutForm($form, (string) $orderId, (string) $this->payment->getAmount()->getNumber());
    $form['#attached']['library'][] = 'razorpay/razorpay.payment';
    $form['#attached']['drupalSettings']['razorpay_checkout_data'] = $checkoutArgs;

    try {
      $configFlags = \Drupal::configFactory()->getEditable('razorpay.settings');
      $settingFlags = (array) $configFlags->get('razorpay_flags');
      $webhookEnableAt = (int) ($settingFlags['webhook_enable_at'] ?? 0);
      if (empty($webhookEnableAt) || ($webhookEnableAt + static::TWELVE_HOURS) < time()) {
        $this->autoWebhook?->autoEnableWebhook($this->config['key_id'], $this->config['key_secret']);
      }
    }
    catch (\Throwable $exception) {
      \Drupal::logger('razorpay')->error($exception->getMessage());
    }

    return $form;
  }

}

