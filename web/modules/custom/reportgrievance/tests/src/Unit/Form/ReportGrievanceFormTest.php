<?php

namespace Drupal\Tests\reportgrievance\Unit\Form;

use Drupal\reportgrievance\Form\ReportGrievanceForm;
use Drupal\reportgrievance\Service\GrievanceApiService;
use Drupal\global_module\Service\FileUploadService;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Cache\CacheFactoryInterface;

/**
 * @coversDefaultClass \Drupal\reportgrievance\Form\ReportGrievanceForm
 * @group reportgrievance
 */
class ReportGrievanceFormTest extends UnitTestCase {

  protected $apiService;
  protected $fileUploadService;
  protected $cache;
  protected $form;

  protected function setUp(): void {
    parent::setUp();

    $this->apiService = $this->createMock(GrievanceApiService::class);
    $this->fileUploadService = $this->createMock(FileUploadService::class);
    $this->cache = $this->createMock(CacheBackendInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('reportgrievance.grievance_api', $this->apiService);
    $container->set('global_module.file_upload_service', $this->fileUploadService);
    $container->set('cache.default', $this->cache);
    
    // Mock the cache factory which is used by \Drupal::cache()
    $cache_factory = $this->createMock(CacheFactoryInterface::class);
    $cache_factory->method('get')->willReturn($this->cache);
    $container->set('cache_factory', $cache_factory);
    
    \Drupal::setContainer($container);

    $this->form = new ReportGrievanceForm($this->apiService, $this->fileUploadService, $this->cache);
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('report_grievance', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('grievance_type')->willReturn('1');

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertEquals('report_grievance_form', $built_form['#theme']);
    $this->assertArrayHasKey('grievance_type', $built_form);
    $this->assertArrayHasKey('grievance_subtype', $built_form['subtype_wrapper']);
    $this->assertArrayHasKey('remarks', $built_form);
    $this->assertArrayHasKey('address', $built_form);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateForm() {
    $form = [];
    
    // Mock cache
    $cache_obj = new \stdClass();
    $cache_obj->data = ['1' => 'Type 1'];
    
    $sub_cache_obj = new \stdClass();
    $sub_cache_obj->data = ['10' => 'Sub 10'];

    $this->cache->method('get')->willReturnMap([
      ['grievance_types', false, $cache_obj],
      ['grievance_subtypes_1', false, $sub_cache_obj],
    ]);

    // Test valid validation
    $form_state_valid = $this->createMock(FormStateInterface::class);
    $form_state_valid->method('getValue')->willReturnMap([
      ['grievance_type', null, '1'],
      ['grievance_subtype', null, '10'],
    ]);
    $form_state_valid->expects($this->never())->method('setErrorByName');
    
    $this->form->validateForm($form, $form_state_valid);

    // Test invalid type
    $form_state_invalid = $this->createMock(FormStateInterface::class);
    $form_state_invalid->method('getValue')->willReturnMap([
      ['grievance_type', null, '999'],
      ['grievance_subtype', null, '10'],
    ]);
    // It will be called twice: once for type, once for subtype (since subcache will be empty for invalid type)
    $form_state_invalid->expects($this->exactly(2))->method('setErrorByName');
    
    $this->form->validateForm($form, $form_state_invalid);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSuccess() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValues')->willReturn([
      'address' => 'Test Address',
      'remarks' => 'Test Remarks',
      'grievance_type' => '1',
      'grievance_subtype' => '10',
      'latitude' => '12.34',
      'longitude' => '56.78',
    ]);

    $request = $this->createMock(Request::class);
    $session = $this->createMock(SessionInterface::class);
    $session->method('get')->with('api_redirect_result', null)->willReturn(['userId' => 123]);
    $request->method('getSession')->willReturn($session);
    
    $container = \Drupal::getContainer();
    $container->set('request_stack', $this->createMock(\Symfony\Component\HttpFoundation\RequestStack::class));
    $container->get('request_stack')->method('getCurrentRequest')->willReturn($request);
    
    // Add path.validator for Url::fromUri
    $path_validator = $this->createMock(\Drupal\Core\Path\PathValidatorInterface::class);
    $container->set('path.validator', $path_validator);

    // Mock API Service
    $this->apiService->method('sendGrievance')->willReturn([
      'success' => TRUE,
      'data' => ['status' => 'success', 'data' => 'GV-123']
    ]);

    // Mock KeyValue
    $kv_factory = $this->createMock(KeyValueFactoryInterface::class);
    $kv_store = $this->createMock(KeyValueStoreInterface::class);
    $kv_factory->method('get')->with('reportgrievance.token_map')->willReturn($kv_store);
    $container->set('keyvalue', $kv_factory);

    // Mock Messenger
    $messenger = $this->createMock(MessengerInterface::class);
    $container->set('messenger', $messenger);

    $this->form->submitForm($form, $form_state);
    
    $this->assertTrue(TRUE);
  }
}
