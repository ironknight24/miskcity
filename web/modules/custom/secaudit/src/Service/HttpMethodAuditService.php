<?php

declare(strict_types=1);

namespace Drupal\secaudit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Audits unexpected or unsupported HTTP methods.
 */
class HttpMethodAuditService
{
  protected RequestStack $requestStack;
  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(RequestStack $request_stack, LoggerChannelFactoryInterface $logger_factory)
  {
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Detects RE1 – Unexpected HTTP Commands.
   */
  public function detectUnexpectedHttpMethod(): void
  {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    $this->logIfMethodDisallowed($request->getMethod(), 'RE1: Unexpected HTTP method detected.');
  }

  /**
   * Detects RE2 – Unsupported HTTP method attempts.
   */
  public function detectUnsupportedHttpMethods(): void
  {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    $this->logIfMethodDisallowed($request->getMethod(), 'RE2: Unsupported HTTP method attempt detected.');
  }

  /**
   * Logs a warning when the request method is disallowed.
   */
  protected function logIfMethodDisallowed(string $method, string $message): void
  {
    $request = $this->requestStack->getCurrentRequest();
    $allowedMethods = ['GET', 'POST'];
    $normalizedMethod = strtoupper($method);

    if (!$request || in_array($normalizedMethod, $allowedMethods, TRUE)) {
      return;
    }

    $this->loggerFactory->get('secaudit')->warning(
      $message,
      [
        'method' => $normalizedMethod,
        'path' => $request->getPathInfo(),
        'ip' => $request->headers->all()['x-real-ip'][0] ?? $request->getClientIp(),
        'user_agent' => $request->headers->get('User-Agent'),
      ]
    );
  }
}
