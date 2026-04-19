<?php

namespace Drupal\login_logout\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Url;

class UserRedirectSubscriber implements EventSubscriberInterface
{

    protected $currentUser;
    protected $currentPath;

    public function __construct(AccountProxyInterface $currentUser, CurrentPathStack $currentPath)
    {
        $this->currentUser = $currentUser;
        $this->currentPath = $currentPath;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 30],
        ];
    }

    public function onRequest(RequestEvent $event)
    {
        if ($event->isMainRequest() && $this->currentUser->isAnonymous()) {
            $path = $this->currentPath->getPath();

            // Don't redirect on login page, register page, or any other allowed path.
            $excluded_paths = [
                // '/user-login',
                // '/user-register',
                // '/user/password',
                // '/ajax', // or any route that needs public access
            ];

            if (!in_array($path, $excluded_paths) && $this->isProtectedPath($path)) {
                $url = Url::fromUri('internal:/user-login', ['query' => ['destination' => ltrim($path, '/')]]);
                $event->setResponse(new RedirectResponse($url->toString()));
            }
        }
    }

    // Custom logic: define which paths are protected (customize as needed)
    private function isProtectedPath($path)
    {
        $protected_prefixes = [
            //   '/your-custom-route',
            '/report-grievances',
            '/my-account',
            '/manage-address',
            '/add-address',
            '/service-request',
            '/request/',
            '/active-sessions',
            '/enquiry'
            //   '/view-name',
            //   '/my-account',
            //   '/another-protected-path',
        ];

        foreach ($protected_prefixes as $prefix) {
            if ($path !== NULL && substr($path, 0, strlen($prefix)) === $prefix) {
                return TRUE;
            }
        }

        return FALSE;
    }
}
