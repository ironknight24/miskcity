<?php

namespace Drupal\Tests\custom_profile\Unit\Form;

use Drupal\custom_profile\Form\ChangePasswordForm;
use Drupal\custom_profile\Service\PasswordChangeService;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\custom_profile\Form\ChangePasswordForm
 * @group custom_profile
 */
class ChangePasswordFormTest extends UnitTestCase {

  protected $passwordChangeService;
  protected $form;

  protected function setUp(): void {
    parent::setUp();

    $this->passwordChangeService = $this->createMock(PasswordChangeService::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('custom_profile.password_change', $this->passwordChangeService);
    \Drupal::setContainer($container);

    $this->form = new ChangePasswordForm($this->passwordChangeService);
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('change_password_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertEquals('change-password', $built_form['#theme']);
    $this->assertArrayHasKey('old_password', $built_form);
    $this->assertArrayHasKey('new_password', $built_form);
    $this->assertArrayHasKey('confirm_password', $built_form);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturnMap([
      ['old_password', null, 'old'],
      ['new_password', null, 'new'],
      ['confirm_password', null, 'new'],
    ]);

    $this->passwordChangeService->method('changePassword')->willReturn([
      'status' => TRUE,
      'message' => 'Success',
    ]);

    $form_state->expects($this->once())
      ->method('setRedirect')
      ->with('global_module.status', [], $this->callback(function($options) {
        return $options['query']['status'] === 1 && $options['query']['message'] === 'Success';
      }));

    $this->form->submitForm($form, $form_state);
  }
}
