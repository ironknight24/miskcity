<?php

namespace Drupal\login_logout\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Psr\Log\LoggerInterface;
use Drupal\login_logout\Service\UserInfoValidator;
use Drupal\Core\Path\CurrentPathStack;

class UserInfoCheckSubscriber implements EventSubscriberInterface
{
    protected $currentUser;
    protected $sessionManager;
    protected $validator;
    protected $logger;
    protected $currentPath;

    public function __construct(
        AccountProxyInterface $current_user,
        SessionManagerInterface $session_manager,
        UserInfoValidator $validator,
        LoggerInterface $logger,
        CurrentPathStack $current_path
    ) {
        $this->currentUser = $current_user;
        $this->sessionManager = $session_manager;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->currentPath = $current_path;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['checkUserInfo', 30],
        ];
    }

    public function checkUserInfo(RequestEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $current_path = $this->currentPath->getPath();

        // Skip validation for certain paths
        $skip_paths = [
            '/response-status', // status page
            '/user-login',      // login page
            '/logout',          // logout page
        ];

        if (in_array($current_path, $skip_paths)) {
            return;
        }

        // Skip for anonymous or admin users
        if ($this->currentUser->isAnonymous() || $this->currentUser->hasPermission('administer site configuration')) {
            return;
        }

        $data = $this->validator->validate();

        if (empty($data)) {
            $this->forceLogout($event);
        }
    }

    protected function forceLogout(RequestEvent $event)
    {
        // Invalidate the user session.
        $this->sessionManager->delete($this->currentUser->id());
        $this->logger->notice('User {uid} logged out due to failed token check.', [
            'uid' => $this->currentUser->id(),
        ]);

        // Redirect to logout route.
        $response = new TrustedRedirectResponse('/logout');
        $event->setResponse($response);
    }
}
