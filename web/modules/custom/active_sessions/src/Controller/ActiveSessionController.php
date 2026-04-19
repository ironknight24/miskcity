<?php

namespace Drupal\active_sessions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\login_logout\Service\OAuthLoginService;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\active_sessions\Service\ActiveSessionService;
use Drupal\active_sessions\Service\ActiveSessionPresenterService;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for managing active sessions.
 *
 * This controller handles displaying and terminating user sessions.
 */
class ActiveSessionController extends ControllerBase
{
    /**
     * OAuth login service.
     *
     * @var OAuthLoginService
     */
    protected $oauthLoginService;

    /**
     * Request stack.
     *
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * Active session service.
     *
     * @var ActiveSessionService
     */
    protected $sessionService;

    /**
     * Date formatter.
     *
     * @var DateFormatterInterface
     */
    protected $dateFormatter;

    /**
     * Session presenter service.
     *
     * @var ActiveSessionPresenterService
     */
    protected $sessionPresenter;

    /**
     * My account path constant.
     */
    public const MY_ACCOUNT_PATH = '/my-account';

    /**
     * Constructs the ActiveSessionController.
     *
     * @param OAuthLoginService $oauthLoginService
     *   The OAuth login service.
     * @param RequestStack $requestStack
     *   The request stack.
     * @param ActiveSessionService $sessionService
     *   The active session service.
     * @param DateFormatterInterface $dateFormatter
     *   The date formatter.
     * @param ActiveSessionPresenterService $sessionPresenter
     *   The session presenter service.
     */
    public function __construct(
        OAuthLoginService $oauthLoginService,
        RequestStack $requestStack,
        ActiveSessionService $sessionService,
        DateFormatterInterface $dateFormatter,
        ActiveSessionPresenterService $sessionPresenter
    ) {
        $this->oauthLoginService = $oauthLoginService;
        $this->requestStack = $requestStack;
        $this->sessionService = $sessionService;
        $this->dateFormatter = $dateFormatter;
        $this->sessionPresenter = $sessionPresenter;
    }

    /**
     * Creates a new instance of the ActiveSessionController.
     *
     * @param ContainerInterface $container
     *   The service container.
     *
     * @return static
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('login_logout.oauth_login_service'),
            $container->get('request_stack'),
            $container->get('active_sessions.session_service'),
            $container->get('date.formatter'),
            $container->get('active_sessions.presenter_service')
        );
    }

    /**
     * Displays the active sessions page.
     *
     * @return array
     *   Render array for the active sessions page.
     */
    public function activeSession(): array
    {
        $session     = \Drupal::service('session');
        $accessToken = $session->get('login_logout.access_token');
        $loginTime   = $session->get('login_logout.login_time');

        $apiSessions = $this->getApiSessions($accessToken);
        [$current, $others] = $this->sessionPresenter->prepareSessions(
            $apiSessions,
            $loginTime,
            (string) $accessToken
        );

        return $this->buildResponse($current, $others);
    }

    /**
     * Retrieves API sessions.
     *
     * @param string|null $accessToken
     *   The access token.
     *
     * @return array
     *   Array of sessions.
     */
    private function getApiSessions(?string $accessToken): array
    {
        if ($accessToken === NULL || $accessToken === '') {
            return [];
        }

        $sessions = $this->sessionService->fetchActiveSessions($accessToken);
        return $sessions['sessions'] ?? [];
    }

    /**
     * Builds the response for the active sessions page.
     *
     * @param array $current
     *   Current user sessions.
     * @param array $others
     *   Other user sessions.
     *
     * @return array
     *   Render array.
     */
    private function buildResponse(array $current, array $others): array
    {
        return [
            '#title' => $this->t('Active Sessions'),
            '#theme' => 'active_sessions_page',
            '#currentUserSessions' => $current,
            '#otherUserSessions'  => $others,
            '#cache' => ['max-age' => 0],
        ];
    }

    /**
     * Ends a specific session.
     *
     * @param string $session_id
     *   The session ID.
     *
     * @return RedirectResponse
     *   The redirect response.
     */
    public function endSession(string $session_id)
    {
        $session = \Drupal::service('session');
        $accessToken = $session->get('login_logout.access_token');
        $active_session_id_token = $session->get('login_logout.active_session_id_token');
        $response = new RedirectResponse(self::MY_ACCOUNT_PATH);

        if ($accessToken === NULL) {
            $this->messenger()->addError($this->t('Failed to retrieve access token.'));
            return $response;
        }

        [$id, $access_token] = explode('--', $session_id) + [NULL, NULL];
        unset($access_token);

        try {
            $is_my_session = ($active_session_id_token == $id);

            if ($is_my_session) {
                $this->sessionService->terminateSession($active_session_id_token, $accessToken);
                $response = new RedirectResponse('/logout');
            } else {
                $this->sessionService->terminateSession($id, $accessToken);
                $response = new RedirectResponse(self::MY_ACCOUNT_PATH);
            }
        } catch (\Exception $e) {
            $this->messenger()->addError(
                $this->t(
                    'An error occurred while ending the session: @message',
                    ['@message' => $e->getMessage()]
                )
            );
        }

        return $response;
    }

    /**
     * Ends all sessions.
     *
     * @return RedirectResponse
     *   The redirect response.
     */
    public function endAllSessions()
    {
        $session = \Drupal::service('session');
        $accessToken = $session->get('login_logout.access_token');

        if ($accessToken === NULL) {
            $this->messenger()->addError($this->t('Failed to retrieve access token.'));
            return new RedirectResponse(self::MY_ACCOUNT_PATH);
        }

        try {
            $this->sessionService->terminateAllOtherSessions($accessToken);
            return new RedirectResponse('/logout');
        } catch (\Exception $e) {
            $this->messenger()->addError($this->t('An error occurred while ending all sessions: @message', ['@message' => $e->getMessage()]));
            return new RedirectResponse(self::MY_ACCOUNT_PATH);
        }
    }
}
