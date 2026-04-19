<?php

declare(strict_types=1);

namespace Drupal\secaudit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Audits cookie and session tampering.
 */
class CookieAuditService
{
  private const PATH_PLACEHOLDER = '@path';
  protected RequestStack $requestStack;
  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(RequestStack $request_stack, LoggerChannelFactoryInterface $logger_factory)
  {
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Detect SE1–SE6 – Session & cookie tampering attempts.
   */
  public function detectCookieTampering(): void
  {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || !$request->hasSession()) {
      return;
    }

    $session = $request->getSession();
    $logger = $this->loggerFactory->get('secaudit');
    $currentCookies = $request->cookies->all();
    $currentIp = $request->headers->all()['x-real-ip'][0] ?? $request->getClientIp();
    $currentUa = (string) $request->headers->get('User-Agent');
    $currentSessionId = $session->getId();
    $snapshot = $session->get('secaudit.session_snapshot');

    if ($snapshot === NULL) {
      $session->set('secaudit.session_snapshot', [
        'cookies' => $currentCookies,
        'ip' => $currentIp,
        'ua' => $currentUa,
        'session_id' => $currentSessionId,
      ]);
      return;
    }

    $previousCookies = $snapshot['cookies'] ?? [];
    $this->logAddedCookies($logger, $currentCookies, $previousCookies, $currentIp, $request->getPathInfo());
    $this->logDeletedCookies($logger, $currentCookies, $previousCookies, $currentIp, $request->getPathInfo());
    $this->logModifiedCookies($logger, $currentCookies, $previousCookies, $currentIp, $request->getPathInfo());
    $this->logSessionIdChanges($logger, $snapshot, $currentSessionId, $currentIp);
    $this->logIpChanges($logger, $snapshot, $currentIp);
    $this->logUserAgentChanges($logger, $snapshot, $currentUa);

    $session->set('secaudit.session_snapshot', [
      'cookies' => $currentCookies,
      'ip' => $currentIp,
      'ua' => $currentUa,
      'session_id' => $currentSessionId,
    ]);
  }

  protected function logAddedCookies($logger, array $currentCookies, array $previousCookies, string $currentIp, string $path): void
  {
    $added = array_diff_key($currentCookies, $previousCookies);
    if (empty($added)) {
      return;
    }

    $logger->warning('SE2: New cookies added during session for IP: @ip and Path: @path Cookies added: @cookies_added', [
      '@ip' => $currentIp,
      self::PATH_PLACEHOLDER => $path,
      '@cookies_added' => implode(', ', array_keys($added)),
      '@uid' => \Drupal::currentUser()->id(),
    ]);
  }

  protected function logDeletedCookies($logger, array $currentCookies, array $previousCookies, string $currentIp, string $path): void
  {
    $deleted = array_diff_key($previousCookies, $currentCookies);
    if (empty($deleted)) {
      return;
    }

    $logger->warning('SE3: Existing cookies deleted during session. IP Address: @ip, Path: @path, Cookies Deleted: @cookies_deleted', [
      '@ip' => $currentIp,
      self::PATH_PLACEHOLDER => $path,
      '@cookies_deleted' => implode(', ', array_keys($deleted)),
      '@uid' => \Drupal::currentUser()->id(),
    ]);
  }

  protected function logModifiedCookies($logger, array $currentCookies, array $previousCookies, string $currentIp, string $path): void
  {
    foreach ($currentCookies as $name => $value) {
      if (!isset($previousCookies[$name])) {
        continue;
      }

      if (hash('sha256', (string) $previousCookies[$name]) === hash('sha256', (string) $value)) {
        continue;
      }

      $logger->warning('SE1: Cookie value modified. IP Address: @ip, Path: @path, Cookie Name: @cookie_name', [
        '@ip' => $currentIp,
        self::PATH_PLACEHOLDER => $path,
        '@cookie_name' => $name,
        '@uid' => \Drupal::currentUser()->id(),
      ]);
    }
  }

  protected function logSessionIdChanges($logger, array $snapshot, string $currentSessionId, string $currentIp): void
  {
    if (empty($snapshot['session_id']) || $snapshot['session_id'] === $currentSessionId) {
      return;
    }

    $logger->warning(
      'SE4: Session ID changed mid-session. IP: @ip | UID: @uid | Old SID: @old_sid | New SID: @new_sid',
      [
        '@ip' => $currentIp,
        '@uid' => \Drupal::currentUser()->id(),
        '@old_sid' => $snapshot['session_id'],
        '@new_sid' => $currentSessionId,
      ]
    );
  }

  protected function logIpChanges($logger, array $snapshot, string $currentIp): void
  {
    if (empty($snapshot['ip']) || $snapshot['ip'] === $currentIp) {
      return;
    }

    $logger->warning('SE5: Source IP changed during active session. Old IP: @old_ip and New IP: @new_ip for User: @uid', [
      '@old_ip' => $snapshot['ip'],
      '@new_ip' => $currentIp,
      '@uid' => \Drupal::currentUser()->id(),
    ]);
  }

  protected function logUserAgentChanges($logger, array $snapshot, string $currentUa): void
  {
    if (empty($snapshot['ua']) || $snapshot['ua'] === $currentUa) {
      return;
    }

    $logger->warning('SE6: User-Agent changed mid-session. Old User Agent: @old_ua, New User Agent: @new_ua', [
      '@old_ua' => $snapshot['ua'],
      '@new_ua' => $currentUa,
      '@uid' => \Drupal::currentUser()->id(),
    ]);
  }
}
