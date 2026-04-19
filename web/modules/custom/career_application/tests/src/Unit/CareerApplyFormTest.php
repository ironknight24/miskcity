<?php

namespace Drupal\Tests\career_application\Unit\Form;

use Drupal\career_application\Form\CareerApplyForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\Entity\File;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * @coversDefaultClass \Drupal\career_application\Form\CareerApplyForm
 * @group career_application
 */
class CareerApplyFormTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * @var \Drupal\Component\Datetime\TimeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $time;

  /**
   * @var \Drupal\career_application\Form\CareerApplyForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->createMock(Connection::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->time = $this->createMock(TimeInterface::class);

    $container = new ContainerBuilder();
    $container->set('database', $this->database);
    $container->set('current_user', $this->currentUser);
    $container->set('datetime.time', $this->time);
    $container->set('string_translation', $this->getStringTranslationStub());
    
    \Drupal::setContainer($container);

    $this->form = new CareerApplyForm();
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('career_apply_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $nid = 123;

    $built_form = $this->form->buildForm($form, $form_state, $nid);

    $this->assertEquals('career_apply_form', $built_form['#theme']);
    $this->assertEquals($nid, $built_form['nid']['#value']);
    $this->assertArrayHasKey('first_name', $built_form);
    $this->assertArrayHasKey('last_name', $built_form);
    $this->assertArrayHasKey('email', $built_form);
    $this->assertArrayHasKey('mobile', $built_form);
    $this->assertArrayHasKey('gender', $built_form);
    $this->assertArrayHasKey('resume', $built_form);
    $this->assertArrayHasKey('submit', $built_form);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    
    $values = [
      'nid' => 123,
      'first_name' => 'John',
      'last_name' => 'Doe',
      'email' => 'john@example.com',
      'mobile' => '1234567890',
      'gender' => 'male',
      'resume' => [0 => 456],
    ];
    $form_state->method('getValues')->willReturn($values);

    $this->currentUser->method('id')->willReturn(1);
    $this->time->method('getCurrentTime')->willReturn(1672531200);

    // Mock File::load static call
    $file = $this->getMockBuilder(File::class)
      ->disableOriginalConstructor()
      ->getMock();
    $file->expects($this->once())->method('setPermanent');
    $file->expects($this->once())->method('save');

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->with(456)->willReturn($file);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('file')->willReturn($file_storage);

    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('file');
    
    \Drupal::getContainer()->set('entity_type.manager', $entity_type_manager);
    \Drupal::getContainer()->set('entity_type.repository', $entity_type_repository);

    // Mock Database insert
    $insert = $this->getMockBuilder(Insert::class)
      ->disableOriginalConstructor()
      ->getMock();
    $this->database->method('insert')->with('career_applications')->willReturn($insert);
    $insert->method('fields')->willReturn($insert);
    $insert->expects($this->once())->method('execute');

    // Mock redirection
    $form_state->expects($this->once())
      ->method('setRedirect')
      ->with('career_application.success_page');

    $this->form->submitForm($form, $form_state);
  }
}
