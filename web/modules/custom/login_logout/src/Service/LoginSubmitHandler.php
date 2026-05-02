<?php

namespace Drupal\login_logout\Service;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\user\UserInterface;
use Drupal\login_logout\Exception\JwtPayloadException;

/**
 * Service to handle user login form submission.
 */
class LoginSubmitHandler {

  use StringTranslationTrait;

  /**
   * The OAuth login service.
   *
   * @var \Drupal\login_logout\Service\OAuthLoginService
   */
  protected $oauthLoginService;

  /**
   * The active session service.
   *
   * @var \Drupal\active_sessions\Service\ActiveSessionService
   */
  protected $activeSessionService;

  /**
   * The vault config service.
   *
   * @var \Drupal\global_module\Service\VaultConfigService
   */
  protected $vaultConfigService;

  /**
   * The apiman token service.
   *
   * @var \Drupal\global_module\Service\ApimanTokenService
   */
  protected $apimanTokenService;

  /**
   * The session.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  protected $session;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The private tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Constructs a new LoginSubmitHandler object.
   */
  public function __construct(
    OAuthLoginService $oauthLoginService,
    \Drupal\active_sessions\Service\ActiveSessionService $activeSessionService,
    \Drupal\global_module\Service\VaultConfigService $vaultConfigService,
    \Drupal\global_module\Service\ApimanTokenService $apimanTokenService,
    SessionInterface $session,
    EntityTypeManagerInterface $entityTypeManager,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $loggerFactory,
    TimeInterface $time,
    PrivateTempStoreFactory $tempStoreFactory
  ) {
    $this->oauthLoginService = $oauthLoginService;
    $this->activeSessionService = $activeSessionService;
    $this->vaultConfigService = $vaultConfigService;
    $this->apimanTokenService = $apimanTokenService;
    $this->session = $session;
    $this->entityTypeManager = $entityTypeManager;
    $this->messenger = $messenger;
    $this->loggerFactory = $loggerFactory;
    $this->time = $time;
    $this->tempStoreFactory = $tempStoreFactory;
  }

  /**
   * Handles the form submission.
   */
  public function handleFormSubmission(array &$form, FormStateInterface $form_state) {
    unset($form);
    $email = trim((string) $form_state->getValue('email'));
    if ($form_state->get('email_validated')) {
      $this->handlePasswordStep($email, $form_state);
    } else {
      $this->handleEmailStep($email, $form_state);
    }
  }

  /**
   * Handles the password step (OAuth login).
   */
  protected function handlePasswordStep($email, FormStateInterface $form_state) {
    $password = $form_state->getValue('password');
    try {
      $tokenData = $this->oauthLoginService->performOAuthLogin($email, $password);

      // Store in session
      $this->session->set('login_logout.access_token', $tokenData['access_token']);
      $this->session->set('login_logout.id_token', $tokenData['id_token']);

      // Decode JWT safely
      $payload = $this->oauthLoginService->decodeJwt($tokenData['id_token']);
      if (empty($payload['sub'])) {
        throw new JwtPayloadException('JWT payload missing "sub" claim.');
      }
      $jwtEmail = $payload['sub'];

      // Save login timestamp
      $login_time = $this->time->getRequestTime();
      $this->session->set('login_logout.login_time', $login_time);

      $accessToken = $tokenData['access_token'] ?? '';
      $activeSessions = $this->activeSessionService->fetchActiveSessions($accessToken);
      $closestSessionId = NULL;
      $closestDiff = PHP_INT_MAX;
      $targetTimeMs = $login_time * 1000;

      if (!empty($activeSessions['sessions'])) {
        foreach ($activeSessions['sessions'] as $session_data) {
          if (!empty($session_data['loginTime'])) {
            $diff = abs($session_data['loginTime'] - $targetTimeMs);
            if ($diff < $closestDiff) {
              $closestDiff = $diff;
              $closestSessionId = $session_data['id'];
            }
          }
        }
      }

      $this->session->set('login_logout.active_session_id_token', $closestSessionId);

      // Step 5: Load Drupal user and log in
      $users = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['mail' => $jwtEmail]);
      $user = reset($users);

      if ($user instanceof UserInterface) {
        user_login_finalize($user);
        $this->messenger->addStatus($this->t('Successfully logged in as @mail', ['@mail' => $jwtEmail]));
        $form_state->setRedirect('<front>');
      } else {
        $this->messenger->addError($this->t('No Drupal user found for @mail', ['@mail' => $jwtEmail]));
      }
    } catch (\Exception $e) {
      $this->loggerFactory->get('login_logout')->error('AE2: Failed Password OAuth2 login failed: @msg for Email: @email', [
        '@msg' => $e->getMessage(),
        '@email' => (string) $email,
      ]);
      $this->messenger->addError($this->t('Login failed: @msg', ['@msg' => $e->getMessage()]));
    }
  }

  /**
   * Handles the email validation step.
   */
  protected function handleEmailStep($email, FormStateInterface $form_state) {
    try {
      $accessToken = $this->apimanTokenService->getApimanAccessToken();
      $globals = $this->vaultConfigService->getGlobalVariables();

      $apiUrl = $globals['apiManConfig']['config']['apiUrl'] ?? '';
      $apiVersion = $globals['apiManConfig']['config']['apiVersion'] ?? '';

      $email = mb_strtolower(trim($email));

        if ($this->oauthLoginService->checkEmailExists($email, $accessToken, $apiUrl, $apiVersion)) {
            $form_state->set('email_validated', TRUE)->setRebuild();
        } else {
            $tempstore = $this->tempStoreFactory->get('login_logout');
            $tempstore->set('registration_email', $email);
            $form_state->setRedirect('login_logout.user_register_form');
        }
    } catch (\Exception $e) {
      $this->messenger->addError($this->t('Error checking email: @msg', ['@msg' => $e->getMessage()]));
    }
  }

}
