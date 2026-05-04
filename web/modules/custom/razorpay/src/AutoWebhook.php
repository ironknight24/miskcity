<?php

namespace Drupal\razorpay;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Razorpay\Api\Api;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ensures Razorpay webhook exists and stays up to date.
 */
class AutoWebhook
{

  use StringTranslationTrait;

  /**
   * @var array<string, bool>
   */
  protected array $supportedWebhookEvents = [
    'payment.authorized' => TRUE,
    'payment.failed' => TRUE,
    'refund.created' => TRUE,
  ];

  /**
   * @var array<string, bool>
   */
  protected array $defaultWebhookEvents = [
    'payment.authorized' => TRUE,
    'payment.failed' => TRUE,
    'refund.created' => TRUE,
  ];

  public function __construct(
    protected RequestStack $requestStack,
    protected MessengerInterface $messenger,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  protected function generateWebhookSecret(): string
  {
    $alphanumericString = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-=~!@#$%^&*()_+,./<>?;:[]{}|abcdefghijklmnopqrstuvwxyz';
    return substr(str_shuffle($alphanumericString), 0, 20);
  }

  public function autoEnableWebhook(string $key_id, string $key_secret): mixed {
    $result = NULL;
  
    try {
      $request = $this->requestStack->getCurrentRequest();
  
      if ($this->isValidRequest($request)) {
        $webhookSecret = $this->prepareWebhookConfig();
  
        $api = new Api($key_id, $key_secret);
        $webhookUrl = $this->getWebhookUrl();
  
        [$webhookExist, $webhookId] = $this->findExistingWebhook($api, $webhookUrl);
  
        $requestBody = [
          'url' => $webhookUrl,
          'active' => TRUE,
          'events' => $this->defaultWebhookEvents,
          'secret' => $webhookSecret,
        ];
  
        if ($webhookExist && $webhookId !== '') {
          $this->logger->info('Updating razorpay webhook.');
          $result = $api->webhook->edit($requestBody, $webhookId);
        }
        else {
          $this->logger->info('Creating razorpay webhook.');
          $result = $api->webhook->create($requestBody);
        }
      }
    }
    catch (\Throwable $exception) {
      $this->messenger->addError(
        $this->t('Razorpay webhook setup failed: @message', ['@message' => $exception->getMessage()])
      );
      $this->logger->error($exception->getMessage());
    }
  
    return $result;
  }

  private function isValidRequest($request): bool {
    if ($request === NULL) {
      return FALSE;
    }
  
    $domainIp = gethostbyname($request->getHost());
  
    if (!filter_var($domainIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      $this->messenger->addError($this->t('Could not enable webhook for localhost.'));
      return FALSE;
    }
  
    return TRUE;
  }

  private function prepareWebhookConfig(): string {
    drupal_flush_all_caches();
  
    $config = $this->configFactory->getEditable('razorpay.settings');
    $flags = (array) $config->get('razorpay_flags');
  
    $secret = empty($flags['webhook_secret'])
      ? $this->generateWebhookSecret()
      : (string) $flags['webhook_secret'];
  
    $config->set('razorpay_flags', [
      'webhook_secret' => $secret,
      'webhook_enable_at' => time(),
    ])->save();
  
    return $secret;
  }

  private function getWebhookUrl(): string {
    return Url::fromRoute(
      'commerce_payment.notify',
      ['commerce_payment_gateway' => 'razorpay'],
      ['absolute' => TRUE]
    )->toString();
  }

  private function findExistingWebhook(Api $api, string $webhookUrl): array {
    $skip = 0;
    $count = 10;
    $webhookItems = [];
    $webhookExist = FALSE;
    $webhookId = '';
  
    do {
      $webhooks = $api->webhook->all(['count' => $count, 'skip' => $skip]);
      $skip += 10;
  
      if (!empty($webhooks['count'])) {
        foreach ($webhooks['items'] as $value) {
          $webhookItems[] = $value;
        }
      }
    }
    while ((int) ($webhooks['count'] ?? 0) === $count);
  
    foreach ($webhookItems as $value) {
      if (($value['url'] ?? '') !== $webhookUrl) {
        continue;
      }
  
      foreach (($value['events'] ?? []) as $eventKey => $eventVal) {
        if ($eventVal == 1 && isset($this->supportedWebhookEvents[$eventKey])) {
          $this->defaultWebhookEvents[$eventKey] = TRUE;
        }
      }
  
      $webhookExist = TRUE;
      $webhookId = (string) ($value['id'] ?? '');
    }
  
    return [$webhookExist, $webhookId];
  }
}
