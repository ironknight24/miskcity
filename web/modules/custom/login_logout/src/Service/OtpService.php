<?php

namespace Drupal\login_logout\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\global_module\Service\VaultConfigService;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Handles OTP generation, delivery, and throttling.
 */
class OtpService {
  use StringTranslationTrait;

  protected const OTP_EVENT = 'get_users_limit';
  protected const OTP_LIMIT = 1;
  protected const OTP_WINDOW = 120;

  protected $httpClient;
  protected $vaultConfigService;
  protected $messenger;
  protected $session;
  protected $flood;
  protected $lock;
  protected $database;
  protected $loggerFactory;
  protected $time;

  public function __construct(
    ClientInterface $httpClient,
    VaultConfigService $vaultConfigService,
    MessengerInterface $messenger,
    SessionInterface $session,
    FloodInterface $flood,
    LockBackendInterface $lock,
    Connection $database,
    LoggerChannelFactoryInterface $loggerFactory,
    TimeInterface $time
  ) {
    $this->httpClient = $httpClient;
    $this->vaultConfigService = $vaultConfigService;
    $this->messenger = $messenger;
    $this->session = $session;
    $this->flood = $flood;
    $this->lock = $lock;
    $this->database = $database;
    $this->loggerFactory = $loggerFactory;
    $this->time = $time;
  }

  /**
   * Generates a six-digit OTP string.
   */
  public function generateOtp(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  }

  /**
   * Applies the OTP rate limit and returns a response if blocked.
   */
  public function enforceOtpRateLimit(string $email): ?JsonResponse {
    $identifier = $this->buildOtpIdentifier($email);
    if ($this->flood->isAllowed(self::OTP_EVENT, self::OTP_LIMIT, self::OTP_WINDOW, $identifier)) {
      return NULL;
    }

    $remaining = $this->getOtpWaitTime($identifier);
    $message = "Rate limit exceeded. Please wait {$remaining} seconds...";
    $this->messenger->addError($this->t(
      '<span class="rate-limit-message" data-wait="@time">@msg</span>',
      [
        '@time' => $remaining,
        '@msg' => $message,
      ]
    ));

    return new JsonResponse([
      'status' => FALSE,
      'message' => $message,
    ], 429);
  }

  /**
   * Sends the OTP while holding a per-email lock.
   */
  public function sendOtpWithLock(array $data, string $otp): ?JsonResponse {
    $email = (string) $data['mail'];
    $identifier = $this->buildOtpIdentifier($email);
    $lockKey = 'otp_lock:' . $email;

    if (!$this->lock->acquire($lockKey)) {
      $this->messenger->addError($this->t('Unable to process OTP request. Please try again.'));
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Unable to process your request at the moment. Please try again later.',
      ], 503);
    }

    try {
      $this->sendOtpWebhookRequest($data, $otp);
      $this->messenger->addStatus($this->t('OTP sent to your mobile/email.'));
      $this->flood->register(self::OTP_EVENT, self::OTP_WINDOW, $identifier);
      return NULL;
    } catch (\Exception $e) {
      $this->loggerFactory->get('register_api')->error('OTP webhook failed: @msg', ['@msg' => $e->getMessage()]);
      $this->messenger->addError($this->t('Failed to send OTP. Please try again later.'));

      return new JsonResponse([
        'status' => FALSE,
        'message' => 'An error occurred while processing your request. Please try again later.',
      ], 500);
    } finally {
      $this->lock->release($lockKey);
    }
  }

  /**
   * Sends the OTP webhook request.
   */
  protected function sendOtpWebhookRequest(array $data, string $otp): void {
    $formName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
    $webhookUrl = $this->vaultConfigService
      ->getGlobalVariables()['applicationConfig']['config']['otpWebhookUrl'];

    $this->httpClient->request('POST', $webhookUrl, [
      'headers' => ['Content-Type' => 'application/json'],
      'json' => [
        'email' => $data['mail'],
        'mobile' => $data['mobile'],
        'otp' => $otp,
        'name' => $formName,
      ],
      'verify' => FALSE,
    ]);
  }

  /**
   * Builds the flood identifier for OTP requests.
   */
  protected function buildOtpIdentifier(string $email): string {
    return 'otp:' . $email . ':' . $this->session->getId();
  }

  /**
   * Reads the remaining wait time for a throttled OTP request.
   */
  protected function getOtpWaitTime(string $identifier): int {
    $lastEvent = $this->database->select('flood', 'f')
      ->fields('f', ['timestamp'])
      ->condition('event', self::OTP_EVENT)
      ->condition('identifier', $identifier)
      ->orderBy('timestamp', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$lastEvent) {
      return self::OTP_WINDOW;
    }

    $elapsed = $this->time->getCurrentTime() - $lastEvent;
    return max(self::OTP_WINDOW - $elapsed, 0);
  }
}
