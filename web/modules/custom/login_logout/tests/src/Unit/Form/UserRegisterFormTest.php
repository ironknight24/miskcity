<?php

namespace Drupal\Tests\login_logout\Unit\Form;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\login_logout\Form\UserRegisterForm;
use Drupal\login_logout\Service\UserRegistrationSubmitHandler;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @coversDefaultClass \Drupal\login_logout\Form\UserRegisterForm
 * @group login_logout
 */
class UserRegisterFormTest extends UnitTestCase
{
  protected $registrationSubmitHandler;
  protected $tempStoreFactory;
  protected $tempStore;
  protected $form;

  protected function setUp(): void
  {
    parent::setUp();

    $this->registrationSubmitHandler = $this->createMock(UserRegistrationSubmitHandler::class);
    $this->tempStoreFactory = $this->createMock(PrivateTempStoreFactory::class);
    $this->tempStore = $this->createMock(PrivateTempStore::class);

    $this->tempStoreFactory->method('get')->with('login_logout')->willReturn($this->tempStore);

    $container = new ContainerBuilder();
    $container->set('tempstore.private', $this->tempStoreFactory);
    $container->set('login_logout.user_registration_submit_handler', $this->registrationSubmitHandler);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->form = new UserRegisterForm($this->registrationSubmitHandler);
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId()
  {
    $this->assertSame('user_register_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildFormForEachPhase()
  {
    $this->tempStore->method('get')->with('registration_email')->willReturn('test@example.com');
    $form = [];

    $phaseOneState = $this->createMock(FormStateInterface::class);
    $phaseOneState->method('get')->with('phase')->willReturn(1);
    $phaseOneForm = $this->form->buildForm($form, $phaseOneState);
    $this->assertArrayHasKey('first_name', $phaseOneForm);
    $this->assertSame('test@example.com', $phaseOneForm['mail']['#default_value']);

    $phaseTwoState = $this->createMock(FormStateInterface::class);
    $phaseTwoState->method('get')->with('phase')->willReturn(2);
    $phaseTwoForm = $this->form->buildForm($form, $phaseTwoState);
    $this->assertArrayHasKey('otp', $phaseTwoForm);

    $phaseThreeState = $this->createMock(FormStateInterface::class);
    $phaseThreeState->method('get')->with('phase')->willReturn(3);
    $phaseThreeForm = $this->form->buildForm($form, $phaseThreeState);
    $this->assertArrayHasKey('password', $phaseThreeForm);
    $this->assertArrayHasKey('confirm_password', $phaseThreeForm);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormDelegatesToHandler()
  {
    $form = [];
    $formState = $this->createMock(FormStateInterface::class);

    $this->registrationSubmitHandler
      ->expects($this->once())
      ->method('handleFormSubmission')
      ->with($form, $formState);

    $this->form->submitForm($form, $formState);
  }

  /**
   * @covers ::create
   */
  public function testCreate()
  {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->willReturnMap([
      ['login_logout.user_registration_submit_handler', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->registrationSubmitHandler],
    ]);

    $form = UserRegisterForm::create($container);
    $this->assertInstanceOf(UserRegisterForm::class, $form);
  }
}
