<?php

declare(strict_types=1);

namespace Drupal\secaudit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Detects direct access to restricted paths.
 */
class ForceBrowsingAuditService
{
  protected RequestStack $requestStack;
  protected LoggerChannelFactoryInterface $loggerFactory;

  public function __construct(RequestStack $request_stack, LoggerChannelFactoryInterface $logger_factory)
  {
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Detect ACE3 – Force Browsing Attempts.
   */
  public function detectForceBrowsing(): void
  {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    $path = $request->getPathInfo();
    $restrictedPatterns = [
      '#^/admin#',
      '#^/user/\d+/edit#',
      '#^/node/\d+/edit#',
      '#^/node/\d+/delete#',
      '#^/admin/config#',
    ];

    foreach ($restrictedPatterns as $pattern) {
      if (!preg_match($pattern, $path)) {
        continue;
      }

      if (!$request->getSession()->has('uid') || !\Drupal::currentUser()->hasPermission('access administration pages')) {
        $this->loggerFactory->get('secaudit')->warning(
          'ACE3 Force Browsing attempt detected.',
          [
            'ip' => $request->headers->all()['x-real-ip'][0] ?? $request->getClientIp(),
            'path' => $path,
            'method' => $request->getMethod(),
            'user_id' => \Drupal::currentUser()->id(),
          ]
        );
      }

      return;
    }
  }
}
