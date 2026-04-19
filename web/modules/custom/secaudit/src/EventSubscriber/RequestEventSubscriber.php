<?php

namespace Drupal\secaudit\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Drupal\secaudit\Service\AuditService;

/**
 * Event subscriber that checks every incoming request for XSS attempts.
 */
class RequestEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var \Drupal\secaudit\Service\AuditService
     */
    protected AuditService $auditService;

    /**
     * Constructor.
     */
    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Reacts to every incoming request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Ignore certain paths (like analytics)
        $path = $request->getPathInfo();
        if ($path !== NULL && substr($path, 0, strlen('/visitors/_track')) === '/visitors/_track') {
            return;
        }

        if ($request->attributes->get('_secaudit_logged')) {
            return;
        }

        $this->auditService->detectEE1();
        $this->auditService->detectIE1();
        $this->auditService->detectEE2();
        $this->auditService->detectForceBrowsing();
        $this->auditService->detectUnexpectedHttpMethod();
        $this->auditService->detectUnsupportedHttpMethods();
        $this->auditService->detectCookieTampering();

        $request->attributes->set('_secaudit_logged', TRUE);
    }


    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 100],
        ];
    }
}
