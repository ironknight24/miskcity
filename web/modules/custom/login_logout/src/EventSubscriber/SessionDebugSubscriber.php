<?php

namespace Drupal\login_logout\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Psr\Log\LoggerInterface;

/**
 * Logs session cookie settings, HTTPS detection, and X-Forwarded headers.
 */
class SessionDebugSubscriber implements EventSubscriberInterface {

  protected LoggerInterface $logger;

  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * Log session cookie settings on each request.
   */
  public function onKernelRequest(RequestEvent $event) {
    // Only log for main requests.
    if (!$event->isMainRequest()) {
      //// return;
    }

    //// $cookies = session_get_cookie_params();
    // $https_status = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'on' : 'off';
    // $forwarded_proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'NOT SET';
    // $remote_addr = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    // // $this->logger->info('Session debug: $_SERVER[HTTPS] = @https', ['@https' => $https_status]);
    // // $this->logger->info('Session debug: X-Forwarded-Proto = @proto', ['@proto' => $forwarded_proto]);
    // // $this->logger->info('Session debug: REMOTE_ADDR = @addr', ['@addr' => $remote_addr]);
    // // $this->logger->info('Session cookie params: @params', ['@params' => json_encode($cookies)]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run early to capture session cookie settings before any output.
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 200],
    ];
  }
}
