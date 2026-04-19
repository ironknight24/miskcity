<?php

namespace Drupal\login_logout\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles security audits for user registration.
 */
class RegistrationAuditService {

  protected $loggerFactory;
  protected $currentUser;
  protected $requestStack;

  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    AccountProxyInterface $currentUser,
    RequestStack $requestStack
  ) {
    $this->loggerFactory = $loggerFactory;
    $this->currentUser = $currentUser;
    $this->requestStack = $requestStack;
  }

  /**
   * Records username anomaly events.
   */
  public function logUsernameAnomalies(string $username): void {
    $context = $this->buildAuditContext();
    $length = strlen($username);

    if ($length < 5 || $length > 254) {
      $this->loggerFactory->get('secaudit')->warning(
        'AE4: Abnormal username length detected for User Id: @uid, IP: @ip, Length: @length',
        $context + ['@length' => $length]
      );
    }

    if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
      $this->loggerFactory->get('secaudit')->warning(
        'AE6: Unexpected characters or format in username detected IP: @ip for User ID: @uid',
        $context + ['@username_sample' => substr($username, 0, 50)]
      );
    }
  }

  /**
   * Records password anomaly events.
   */
  public function logPasswordAnomalies(string $password): void {
    $context = $this->buildAuditContext();
    $length = strlen($password);

    if ($length < 8 || $length > 128) {
      $this->loggerFactory->get('secaudit')->warning(
        'AE5: Abnormal password length detected for User Id: @uid, IP: @ip, Length: @length',
        $context + ['@length' => $length]
      );
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $password)) {
      $this->loggerFactory->get('secaudit')->warning(
        'AE7: Control characters detected in password for User Id: @uid, IP: @ip',
        $context
      );
    }
  }

  /**
   * Builds common security audit context.
   */
  protected function buildAuditContext(): array {
    return [
      '@uid' => $this->currentUser->id() ?: 0,
      '@ip' => $this->getClientIp(),
    ];
  }

  /**
   * Returns the request IP while preserving x-real-ip behaviour.
   */
  protected function getClientIp(): string {
    $request = $this->requestStack->getCurrentRequest();
    $headers = $request?->headers->all() ?? [];
    return $headers['x-real-ip'][0] ?? 'UNKNOWN';
  }
}
