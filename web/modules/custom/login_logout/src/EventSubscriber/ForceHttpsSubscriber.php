<?php

namespace Drupal\login_logout\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Force HTTPS detection very early, before session starts.
 */
class ForceHttpsSubscriber implements EventSubscriberInterface {

  public function onKernelRequest(RequestEvent $event) {
    if (!$event->isMainRequest()) {
      return;
    }

    // Detect HTTPS from ingress
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
      $_SERVER['HTTPS'] = 'on';

      // Only set ini if session has not started
      if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_secure', '1');
      }
    }
  }

  public static function getSubscribedEvents(): array {
    // Very early priority to run before session_start
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 255],
    ];
  }
}
