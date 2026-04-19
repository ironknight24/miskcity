<?php

namespace Drupal\login_logout\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\active_sessions\Service\ActiveSessionService;
use Drupal\login_logout\Exception\JwtPayloadException;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Finalizes account creation and login after registration.
 */
class UserRegistrationAccountManager
{
  use StringTranslationTrait;

  /**
   * @var \Drupal\login_logout\Service\OAuthLoginService
   */
  protected $oauthLoginService;

  /**
   * @var \Drupal\active_sessions\Service\ActiveSessionService
   */
  protected $activeSessionService;

  /**
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  protected $session;

  /**
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  public function __construct(
    OAuthLoginService $oauthLoginService,
    ActiveSessionService $activeSessionService,
    SessionInterface $session,
    TimeInterface $time,
    MessengerInterface $messenger,
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->oauthLoginService = $oauthLoginService;
    $this->activeSessionService = $activeSessionService;
    $this->session = $session;
    $this->time = $time;
    $this->messenger = $messenger;
    $this->moduleHandler = $moduleHandler;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Completes login, user creation, and redirect after registration.
   */
  public function finalizeRegistration(array $data, string $password, FormStateInterface $form_state): void
  {
    $tokenData = $this->performLoginAfterRegistration((string) ($data['mail'] ?? ''), $password);
    if (!$this->hasRequiredTokens($tokenData)) {
      $this->messenger->addError($this->t('Token not received.'));
      return;
    }

    if (!$this->hasValidJwtSubject((string) $tokenData['id_token'])) {
      throw new JwtPayloadException('JWT payload missing "sub" claim.');
    }

    $loginTime = $this->time->getRequestTime();
    $this->storeTokens($tokenData, $loginTime);
    $this->session->set(
      'login_logout.active_session_id_token',
      $this->findClosestActiveSessionId((string) $tokenData['access_token'], $loginTime)
    );

    $user = $this->createDrupalUser($data, $password);
    $this->finalizeUserLogin($user);
    $this->messenger->addStatus($this->t('Registered and logged in successfully.'));
    $form_state->setRedirect('<front>');
  }

  /**
   * Runs the OAuth flow after successful registration.
   */
  protected function performLoginAfterRegistration(string $email, string $password): ?array
  {
    $flowId = $this->oauthLoginService->getFlowId();
    if (!$flowId) {
      $this->messenger->addError($this->t('Flow ID not received.'));
      return NULL;
    }

    $authorizationCode = $this->oauthLoginService->authenticateUser($flowId, $email, $password);
    if (empty($authorizationCode['code'])) {
      $message = $authorizationCode['message'] ?? $this->t('Authorization failed.');
      $this->messenger->addError($this->t($message));
      return NULL;
    }

    return $this->oauthLoginService->exchangeCodeForToken($authorizationCode['code']);
  }

  /**
   * Checks whether OAuth returned the expected tokens.
   */
  protected function hasRequiredTokens(?array $tokenData): bool
  {
    return !empty($tokenData['access_token']) && !empty($tokenData['id_token']);
  }

  /**
   * Validates the JWT subject claim.
   */
  protected function hasValidJwtSubject(string $idToken): bool
  {
    $payload = $this->oauthLoginService->decodeJwt($idToken);
    return !empty($payload['sub']);
  }

  /**
   * Stores tokens and login time in session.
   */
  protected function storeTokens(array $tokenData, int $loginTime): void
  {
    $this->session->set('login_logout.access_token', $tokenData['access_token']);
    $this->session->set('login_logout.id_token', $tokenData['id_token']);
    $this->session->set('login_logout.login_time', $loginTime);
  }

  /**
   * Resolves the closest active session ID for the current login.
   */
  protected function findClosestActiveSessionId(string $accessToken, int $loginTime): ?string
  {
    $closestSessionId = NULL;
    $closestDiff = PHP_INT_MAX;
    $targetTimeMs = $loginTime * 1000;

    foreach ($this->activeSessionService->fetchActiveSessions($accessToken)['sessions'] ?? [] as $session) {
      $candidate = $this->extractCloserSessionId($session, $targetTimeMs, $closestDiff);
      if ($candidate === NULL) {
        continue;
      }

      $closestDiff = abs($session['loginTime'] - $targetTimeMs);
      $closestSessionId = $candidate;
    }

    return $closestSessionId;
  }

  /**
   * Returns a better session candidate when one exists.
   */
  protected function extractCloserSessionId(array $session, int $targetTimeMs, int $closestDiff): ?string
  {
    if (empty($session['loginTime'])) {
      return NULL;
    }

    $diff = abs($session['loginTime'] - $targetTimeMs);
    if ($diff >= $closestDiff) {
      return NULL;
    }

    return $session['id'] ?? NULL;
  }

  /**
   * Creates the Drupal user entity.
   */
  protected function createDrupalUser(array $data, string $password): UserInterface
  {
    $user = $this->entityTypeManager->getStorage('user')->create([
      'name' => $data['first_name'],
      'mail' => $data['mail'],
      'pass' => $password,
      'status' => 1,
      'field_first_name' => $data['first_name'],
      'field_last_name' => $data['last_name'],
      'field_country_code' => $data['country_code'],
      'field_mobile_number' => $data['mobile'],
    ]);

    $user->enforceIsNew();
    $user->save();

    return $user;
  }

  /**
   * Completes the Drupal login using the module helper if present.
   */
  protected function finalizeUserLogin(UserInterface $user): void
  {
    $globalCallable = 'user_login_finalize';
    if ($this->moduleHandler->moduleExists('user') && function_exists($globalCallable)) {
      $globalCallable($user);
      return;
    }

    $namespacedCallable = 'Drupal\\login_logout\\Form\\user_login_finalize';
    if (function_exists($namespacedCallable)) {
      $namespacedCallable($user);
    }
  }
}
