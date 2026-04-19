<?php

namespace Drupal\login_logout\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\login_logout\Service\OAuthLoginService;

class LogoutController extends ControllerBase
{

    protected $currentUser;
    protected $sessionManager;
    protected $requestStack;
    protected $oauthLoginService;


    public function __construct(AccountProxyInterface $current_user, SessionManagerInterface $session_manager, RequestStack $request_stack, OAuthLoginService $oauthLoginService)
    {
        $this->currentUser = $current_user;
        $this->sessionManager = $session_manager;
        $this->requestStack = $request_stack;
        $this->oauthLoginService = $oauthLoginService;
    }

    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('current_user'),
            $container->get('session_manager'),
            $container->get('request_stack'),
            $container->get('login_logout.oauth_login_service'),

        );
    }

    public function logout()
    {
        if ($this->currentUser->isAuthenticated()) {
            $session = $this->requestStack->getCurrentRequest()->getSession();
            $id_token = $session->get('login_logout.id_token');
            if (!$id_token) {
                $this->messenger()->addError($this->t('No ID token found in session.'));
                return new RedirectResponse('/');
            }

            try {
                // Call the logout method from OAuthLoginService
                $response = $this->oauthLoginService->logout($id_token);
                if ($response['status'] === 200) {
                    // Clear session data
                    $session->remove('login_logout.access_token');
                    $session->remove('login_logout.id_token');
                    $session->remove('login_logout.active_session_id_token');
                    $this->sessionManager->destroy();
                }
                $this->messenger()->addStatus($this->t('You have been logged out.'));
            } catch (\Exception $e) {
                $this->messenger()->addError($this->t('An error occurred during logout: @message', ['@message' => $e->getMessage()]));
                return new RedirectResponse('/');
            }
            // $this->currentUser->logout(); // Optional — mostly handled by destroy()
        }

        return new RedirectResponse('/');
    }
}
