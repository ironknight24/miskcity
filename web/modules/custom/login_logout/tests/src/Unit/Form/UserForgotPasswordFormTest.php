<?php

namespace Drupal\Tests\login_logout\Unit\Form;

use Drupal\login_logout\Form\UserForgotPasswordForm;
use Drupal\login_logout\Service\PasswordRecoveryService;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @coversDefaultClass \Drupal\login_logout\Form\UserForgotPasswordForm
 * @group login_logout
 */
class UserForgotPasswordFormTest extends UnitTestCase {

  protected $passwordRecoveryService;
  protected $tempStoreFactory;
  protected $tempStore;
  protected $messenger;
  protected $loggerFactory;
  protected $logger;
  protected $form;

  protected function setUp(): void {
    parent::setUp();

    $this->passwordRecoveryService = $this->createMock(PasswordRecoveryService::class);
    $this->tempStoreFactory = $this->createMock(PrivateTempStoreFactory::class);
    $this->tempStore = $this->createMock(PrivateTempStore::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->tempStoreFactory->method('get')->with('login_logout')->willReturn($this->tempStore);
    $this->loggerFactory->method('get')->with('login_logout')->willReturn($this->logger);

    $container = new ContainerBuilder();
    $container->set('messenger', $this->messenger);
    $container->set('logger.factory', $this->loggerFactory);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->form = new UserForgotPasswordForm(
      $this->passwordRecoveryService,
      $this->tempStoreFactory
    );
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('login_logout_forgot_password_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertArrayHasKey('email', $built_form);
    $this->assertEquals('email', $built_form['email']['#type']);
    $this->assertArrayHasKey('actions', $built_form);
    $this->assertArrayHasKey('submit', $built_form['actions']);
    $this->assertEquals('login_logout_forgot_password', $built_form['#theme']);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSuccess() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('email')->willReturn('test@example.com');

    $this->passwordRecoveryService->expects($this->once())
      ->method('recoverPassword')
      ->with('test@example.com')
      ->willReturn(['status' => 'success']);

    $this->tempStore->expects($this->once())
      ->method('set')
      ->with('recovery_email', 'test@example.com');

    $form_state->expects($this->once())
      ->method('setRedirect')
      ->with('login_logout.password_recovery_status');

    $this->form->submitForm($form, $form_state);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormFailure() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('email')->willReturn('test@example.com');

    $this->passwordRecoveryService->expects($this->once())
      ->method('recoverPassword')
      ->with('test@example.com')
      ->willReturn(NULL);

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with($this->anything());

    $this->form->submitForm($form, $form_state);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormException() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('email')->willReturn('test@example.com');

    $this->passwordRecoveryService->expects($this->once())
      ->method('recoverPassword')
      ->with('test@example.com')
      ->willThrowException(new \Exception('API Failure'));

    $this->logger->expects($this->once())
      ->method('error')
      ->with('Password recovery failed: @msg', ['@msg' => 'API Failure']);

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with($this->anything());

    $this->form->submitForm($form, $form_state);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnMap([
      ['login_logout.password_recovery_service', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->passwordRecoveryService],
      ['tempstore.private', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->tempStoreFactory],
    ]);

    $form = UserForgotPasswordForm::create($container);
    $this->assertInstanceOf(UserForgotPasswordForm::class, $form);
  }
}
