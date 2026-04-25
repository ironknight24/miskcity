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
class AutoWebhook {

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

  protected function generateWebhookSecret(): string {
    $alphanumericString = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-=~!@#$%^&*()_+,./<>?;:[]{}|abcdefghijklmnopqrstuvwxyz';
    return substr(str_shuffle($alphanumericString), 0, 20);
  }

  public function autoEnableWebhook(string $key_id, string $key_secret): mixed {
    try {
      $request = $this->requestStack->getCurrentRequest();
      if ($request === NULL) {
        return NULL;
      }
      $domainIp = gethostbyname($request->getHost());
      if (!filter_var($domainIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $this->messenger->addError($this->t('Could not enable webhook for localhost.'));
        return NULL;
      }

      drupal_flush_all_caches();

      $config = $this->configFactory->getEditable('razorpay.settings');
      $settingFlags = (array) $config->get('razorpay_flags');
      $webhookSecret = empty($settingFlags['webhook_secret']) ? $this->generateWebhookSecret() : (string) $settingFlags['webhook_secret'];
      $config->set('razorpay_flags', [
        'webhook_secret' => $webhookSecret,
        'webhook_enable_at' => time(),
      ])->save();

      $skip = 0;
      $count = 10;
      $webhookItems = [];
      $webhookExist = FALSE;
      $webhookId = '';
      $webhookUrl = Url::fromRoute('commerce_payment.notify', ['commerce_payment_gateway' => 'razorpay'], ['absolute' => TRUE])->toString();
      $api = new Api($key_id, $key_secret);

      do {
        $webhooks = $api->webhook->all(['count' => $count, 'skip' => $skip]);
        $skip += 10;
        if (!empty($webhooks['count'])) {
          foreach ($webhooks['items'] as $value) {
            $webhookItems[] = $value;
          }
        }
      } while ((int) ($webhooks['count'] ?? 0) === $count);

      $requestBody = [
        'url' => $webhookUrl,
        'active' => TRUE,
        'events' => $this->defaultWebhookEvents,
        'secret' => $webhookSecret,
      ];

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

      if ($webhookExist && $webhookId !== '') {
        $this->logger->info('Updating razorpay webhook.');
        return $api->webhook->edit($requestBody, $webhookId);
      }

      $this->logger->info('Creating razorpay webhook.');
      return $api->webhook->create($requestBody);
    }
    catch (\Throwable $exception) {
      $this->messenger->addError($this->t('Razorpay webhook setup failed: @message', ['@message' => $exception->getMessage()]));
      $this->logger->error($exception->getMessage());
      return NULL;
    }
  }

}

