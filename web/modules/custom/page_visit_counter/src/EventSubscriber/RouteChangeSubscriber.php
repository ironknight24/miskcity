<?php

namespace Drupal\page_visit_counter\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\State\StateInterface;

class RouteChangeSubscriber implements EventSubscriberInterface
{

  protected SessionInterface $session;
  protected StateInterface $state;

  public function __construct(SessionInterface $session, StateInterface $state)
  {
    $this->session = $session;
    $this->state = $state;
  }

  public static function getSubscribedEvents(): array
  {
    return [
      KernelEvents::RESPONSE => ['onKernelResponse', 30],
    ];
  }

  public function onKernelResponse(ResponseEvent $event): void
  {
    $request = $event->getRequest();
    $response = $event->getResponse();
    $current_path = $request->getPathInfo();

    $should_process =
      $event->isMainRequest()
      && !$request->isXmlHttpRequest()
      && $request->getRequestFormat() === 'html'
      && $response->getStatusCode() === 200
      && !(
        $current_path !== NULL &&
        (
          str_starts_with($current_path, '/admin') ||
          str_starts_with($current_path, '/core') ||
          str_starts_with($current_path, '/system') ||
          str_starts_with($current_path, '/_')
        )
      )
      && $this->session->get('page_visit_counted_for') !== $current_path;

    if ($should_process) {
      $count = $this->state->get('page_visit_counter.count', 0);
      $this->state->set('page_visit_counter.count', $count + 1);
      $this->session->set('page_visit_counted_for', $current_path);
    }
  }
}
