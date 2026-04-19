<?php

namespace Drupal\login_logout\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Ensures authenticated pages are not cached by the browser.
 */
class NoCacheSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new NoCacheSubscriber.
   */
  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::RESPONSE => ['addNoCacheHeaders'],
    ];
  }

  /**
   * Add cache prevention headers for authenticated users.
   */
  public function addNoCacheHeaders(ResponseEvent $event) {
    if ($this->currentUser->isAuthenticated()) {
      $response = $event->getResponse();

      $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');
    }
  }
}
