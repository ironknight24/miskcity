<?php

namespace Drupal\login_logout\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reject requests with disallowed Origin headers.
 */
class OriginGuardSubscriber implements EventSubscriberInterface, ContainerInjectionInterface {

  protected array $allowedOrigins;

  public function __construct(array $corsConfig) {
    $this->allowedOrigins = $corsConfig['allowedOrigins'] ?? [];
    // Normalize (remove trailing slashes).
    $this->allowedOrigins = array_map(fn($o) => rtrim($o, '/'), $this->allowedOrigins);
  }

  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['checkOrigin', 300],
    ];
  }

  public function checkOrigin(RequestEvent $event) {
    $request = $event->getRequest();
    $origin = $request->headers->get('Origin');

    if (!$origin) {
      return; // No Origin header → ignore.
    }

    $normalized = rtrim($origin, '/');
    if (!in_array($normalized, $this->allowedOrigins, TRUE)) {
      $response = new Response(json_encode([
        'error' => 'Invalid CORS',
        'origin' => $origin,
      ]), 403, ['Content-Type' => 'application/json']);
      $event->setResponse($response);
    }
  }

  public static function create(ContainerInterface $container) {
    return new static($container->getParameter('cors.config'));
  }

}
