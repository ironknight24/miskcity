<?php

namespace Drupal\Tests\custom_profile\Unit\Form;

use Drupal\custom_profile\Form\AddAddressForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use GuzzleHttp\ClientInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;

/**
 * @coversDefaultClass \Drupal\custom_profile\Form\AddAddressForm
 * @group custom_profile
 */
class AddAddressFormTest extends UnitTestCase {

  protected $httpClient;
  protected $form;

  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('http_client', $this->httpClient);
    
    \Drupal::setContainer($container);

    $this->form = new AddAddressForm($this->httpClient);
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('add_address__form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertEquals('add_address_form', $built_form['#theme']);
    $this->assertArrayHasKey('postal_code', $built_form);
    $this->assertArrayHasKey('flat', $built_form);
    $this->assertArrayHasKey('area', $built_form);
  }

  /**
   * @covers ::validatePostalCode
   */
  public function testValidatePostalCode() {
    $complete_form = [];

    // Valid
    $form_state_valid = $this->createMock(FormStateInterface::class);
    $element_valid = ['#value' => '123456'];
    $form_state_valid->expects($this->never())->method('setError');
    AddAddressForm::validatePostalCode($element_valid, $form_state_valid, $complete_form);

    // Invalid
    $form_state_invalid = $this->createMock(FormStateInterface::class);
    $element_invalid = ['#value' => 'abc'];
    $form_state_invalid->expects($this->once())->method('setError');
    AddAddressForm::validatePostalCode($element_invalid, $form_state_invalid, $complete_form);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormCreate() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValues')->willReturn([
      'postal_code' => '123456',
      'area' => 'Area',
      'landmark' => 'Landmark',
      'country' => 'Country',
      'address_type' => 'home',
      'flat' => 'Flat',
    ]);

    // Mock Node::create
    $node = $this->getMockBuilder(Node::class)
      ->disableOriginalConstructor()
      ->getMock();
    $node->expects($this->once())->method('save');

    $entity_storage = $this->createMock(EntityStorageInterface::class);
    $entity_storage->method('create')->willReturn($node);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')->willReturn($entity_storage);

    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('node');

    $container = \Drupal::getContainer();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('entity_type.repository', $entity_type_repository);
    
    $current_user = $this->createMock(\Drupal\Core\Session\AccountProxyInterface::class);
    $current_user->method('id')->willReturn(1);
    $container->set('current_user', $current_user);

    $messenger = $this->createMock(\Drupal\Core\Messenger\MessengerInterface::class);
    $container->set('messenger', $messenger);

    $this->form->submitForm($form, $form_state);
  }
}
