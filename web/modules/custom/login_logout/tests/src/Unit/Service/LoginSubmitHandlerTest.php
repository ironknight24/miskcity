<?php

namespace Drupal\login_logout\Service {
  if (!function_exists('Drupal\login_logout\Service\user_login_finalize')) {
    function user_login_finalize($account) {
      // Mock global function for unit test.
    }
  }
}

namespace Drupal\Tests\login_logout\Unit\Service {

use Drupal\login_logout\Service\LoginSubmitHandler;
use Drupal\Core\Form\FormStateInterface;
use Drupal\login_logout\Service\OAuthLoginService;
use Drupal\active_sessions\Service\ActiveSessionService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\PrivateTempStore;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\LoginSubmitHandler
 * @covers ::__construct
 * @group login_logout
 */
class LoginSubmitHandlerTest extends UnitTestCase {

  protected $oauthLoginService;
  protected $activeSessionService;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $session;
  protected $entityTypeManager;
  protected $messenger;
  protected $loggerFactory;
  protected $logger;
  protected $time;
  protected $tempStoreFactory;
  protected $handler;

  protected function setUp(): void {
    parent::setUp();

    $this->oauthLoginService = $this->createMock(OAuthLoginService::class);
    $this->activeSessionService = $this->createMock(ActiveSessionService::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);
    $this->session = $this->createMock(SessionInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->tempStoreFactory = $this->createMock(PrivateTempStoreFactory::class);

    $this->loggerFactory->method('get')->willReturn($this->logger);

    $this->handler = new LoginSubmitHandler(
      $this->oauthLoginService,
      $this->activeSessionService,
      $this->vaultConfigService,
      $this->apimanTokenService,
      $this->session,
      $this->entityTypeManager,
      $this->messenger,
      $this->loggerFactory,
      $this->time,
      $this->tempStoreFactory
    );
    $this->handler->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * @covers ::handleFormSubmission
   * @covers ::handleEmailStep
   */
  public function testHandleFormSubmissionEmailStep() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('get')->with('email_validated')->willReturn(FALSE);
    $form_state->method('getValue')->with('email')->willReturn('Test@Example.com ');

    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('token');
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.com', 'apiVersion' => 'v1']]
    ]);

    $this->oauthLoginService->expects($this->once())
      ->method('checkEmailExists')
      ->with('test@example.com', 'token', 'http://api.com', 'v1')
      ->willReturn(TRUE);

    $form_state->expects($this->once())->method('set')->with('email_validated', TRUE)->willReturnSelf();
    $form_state->expects($this->once())->method('setRebuild');

    $this->handler->handleFormSubmission($form, $form_state);
  }

  /**
   * @covers ::handleFormSubmission
   * @covers ::handlePasswordStep
   */
  public function testHandleFormSubmissionPasswordStepSuccess() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('get')->with('email_validated')->willReturn(TRUE);
    $form_state->method('getValue')->willReturnMap([
      ['email', null, 'test@example.com'],
      ['password', null, 'password123'],
    ]);

    $this->oauthLoginService->method('performOAuthLogin')->willReturn([
      'access_token' => 'at',
      'id_token' => 'it'
    ]);

    $this->oauthLoginService->method('decodeJwt')->willReturn(['sub' => 'test@example.com']);
    $this->time->method('getRequestTime')->willReturn(1000);

    $this->activeSessionService->method('fetchActiveSessions')->willReturn([
      'sessions' => [
        ['id' => 's1', 'loginTime' => 990000],
        ['id' => 's2', 'loginTime' => 1000000],
      ]
    ]);

    $user = $this->createMock(UserInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')->with('user')->willReturn($storage);
    $storage->method('loadByProperties')->with(['mail' => 'test@example.com'])->willReturn([$user]);

    $this->session->expects($this->exactly(4))->method('set');
    $form_state->expects($this->once())->method('setRedirect')->with('<front>');

    $this->handler->handleFormSubmission($form, $form_state);
  }

  /**
   * @covers ::handleEmailStep
   */
  public function testHandleFormSubmissionEmailNotFound() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('get')->with('email_validated')->willReturn(FALSE);
    $form_state->method('getValue')->with('email')->willReturn('New@Example.com ');

    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('token');
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([]);
    $this->oauthLoginService->expects($this->once())
      ->method('checkEmailExists')
      ->with('new@example.com', 'token', '', '')
      ->willReturn(FALSE);

    $tempStore = $this->createMock(PrivateTempStore::class);
    $this->tempStoreFactory->method('get')->with('login_logout')->willReturn($tempStore);
    $tempStore->expects($this->once())->method('set')->with('registration_email', 'new@example.com');

    $form_state->expects($this->once())->method('setRedirect')->with('login_logout.user_register_form');

    $this->handler->handleFormSubmission($form, $form_state);
  }

  /**
   * @covers ::handleEmailStep
   */
  public function testHandleEmailStepException() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('get')->with('email_validated')->willReturn(FALSE);
    $form_state->method('getValue')->with('email')->willReturn('test@example.com');

    $this->apimanTokenService->method('getApimanAccessToken')->willThrowException(new \Exception('API Error'));
    $this->messenger->expects($this->once())->method('addError')->with($this->callback(function($markup) {
        return $markup instanceof TranslatableMarkup && strpos($markup->getUntranslatedString(), 'Error checking email') !== false;
    }));

    $this->handler->handleFormSubmission($form, $form_state);
  }

  /**
   * @covers ::handlePasswordStep
   */
  public function testHandlePasswordStepOAuthFailure() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('get')->with('email_validated')->willReturn(TRUE);
    $form_state->method('getValue')->willReturnMap([
      ['email', NULL, 'test@example.com'],
      ['password', NULL, 'wrong'],
    ]);

    $this->oauthLoginService->method('performOAuthLogin')->willThrowException(new \Exception('OAuth Failed'));
    $this->logger->expects($this->once())->method('error')->with($this->stringContains('AE2'));
    $this->messenger->expects($this->once())->method('addError')->with($this->callback(function($markup) {
        return $markup instanceof TranslatableMarkup && strpos($markup->getUntranslatedString(), 'Login failed') !== false;
    }));

    $this->handler->handleFormSubmission($form, $form_state);
  }

  /**
   * @covers ::handlePasswordStep
   */
  public function testHandlePasswordStepJwtMissingSub() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('get')->with('email_validated')->willReturn(TRUE);
    $form_state->method('getValue')->willReturnMap([
      ['email', NULL, 'test@example.com'],
      ['password', NULL, 'pass'],
    ]);

    $this->oauthLoginService->method('performOAuthLogin')->willReturn(['id_token' => 'it', 'access_token' => 'at']);
    $this->oauthLoginService->method('decodeJwt')->willReturn([]); // Missing 'sub'

    $this->logger->expects($this->once())->method('error');
    $this->messenger->expects($this->once())->method('addError');

    $this->handler->handleFormSubmission($form, $form_state);
  }

  /**
   * @covers ::handlePasswordStep
   */
  public function testHandlePasswordStepNoDrupalUser() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('get')->with('email_validated')->willReturn(TRUE);
    $form_state->method('getValue')->willReturnMap([
      ['email', NULL, 'test@example.com'],
      ['password', NULL, 'pass'],
    ]);

    $this->oauthLoginService->method('performOAuthLogin')->willReturn(['id_token' => 'it', 'access_token' => 'at']);
    $this->oauthLoginService->method('decodeJwt')->willReturn(['sub' => 'test@example.com']);
    $this->time->method('getRequestTime')->willReturn(1000);
    $this->activeSessionService->method('fetchActiveSessions')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')->with('user')->willReturn($storage);
    $storage->method('loadByProperties')->willReturn([]); // No user found

    $this->messenger->expects($this->once())->method('addError')->with($this->callback(function($markup) {
        return $markup instanceof TranslatableMarkup && strpos($markup->getUntranslatedString(), 'No Drupal user found') !== false;
    }));

    $this->handler->handleFormSubmission($form, $form_state);
  }

}

}
