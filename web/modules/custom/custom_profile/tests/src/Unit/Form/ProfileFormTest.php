<?php

namespace Drupal\Tests\custom_profile\Unit\Form;

use Drupal\custom_profile\Form\ProfileForm;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Drupal\Core\Ajax\AjaxResponse;

/**
 * @coversDefaultClass \Drupal\custom_profile\Form\ProfileForm
 * @group custom_profile
 */
class ProfileFormTest extends UnitTestCase {

  protected $globalVariablesService;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $httpClient;
  protected $form;

  protected function setUp(): void {
    parent::setUp();

    $this->globalVariablesService = $this->createMock(GlobalVariablesService::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);
    
    $this->httpClient = $this->getMockBuilder(Client::class)
      ->disableOriginalConstructor()
      ->getMock();

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('global_module.global_variables', $this->globalVariablesService);
    $container->set('global_module.vault_config_service', $this->vaultConfigService);
    $container->set('global_module.apiman_token_service', $this->apimanTokenService);
    $container->set('http_client', $this->httpClient);
    
    $request = $this->createMock(Request::class);
    $session = $this->createMock(SessionInterface::class);
    $request->method('getSession')->willReturn($session);
    
    $request_stack = $this->createMock(\Symfony\Component\HttpFoundation\RequestStack::class);
    $request_stack->method('getCurrentRequest')->willReturn($request);
    $container->set('request_stack', $request_stack);

    \Drupal::setContainer($container);

    $this->form = new ProfileForm($this->globalVariablesService, $this->vaultConfigService, $this->apimanTokenService);
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('profile_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertArrayHasKey('first_name', $built_form);
    $this->assertArrayHasKey('last_name', $built_form);
    $this->assertArrayHasKey('email', $built_form);
    $this->assertArrayHasKey('mobile', $built_form);
    $this->assertArrayHasKey('gender', $built_form);
    $this->assertArrayHasKey('dob', $built_form);
    $this->assertArrayHasKey('address', $built_form);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturnMap([
      ['first_name', null, 'John'],
      ['last_name', null, 'Doe'],
      ['address', null, 'Valid Address'],
      ['dob', null, '1990-01-01'],
      ['gender', null, '1'],
    ]);

    $form_state->expects($this->never())->method('setErrorByName');
    $this->form->validateForm($form, $form_state);
  }

  /**
   * @covers ::validateForm
   */
  public function testValidateFormInvalid() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturnMap([
      ['first_name', null, '123'], // Invalid
      ['last_name', null, ''], // Invalid
      ['address', null, 'abc'], // Too short
      ['dob', null, '2099-01-01'], // Future
      ['gender', null, ''], // Missing
    ]);

    $form_state->expects($this->atLeastOnce())->method('setErrorByName');
    $this->form->validateForm($form, $form_state);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturn('some_value');

    $session = \Drupal::request()->getSession();
    $session->method('get')->with('api_redirect_result')->willReturn(['tenantCode' => 'tenant']);

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.com/', 'apiVersion' => '/v1/']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn(json_encode(['status' => TRUE]));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('post')->willReturn($response);

    // Mock messenger
    $messenger = $this->createMock(\Drupal\Core\Messenger\MessengerInterface::class);
    \Drupal::getContainer()->set('messenger', $messenger);

    $this->form->submitForm($form, $form_state);
  }

  /**
   * @covers ::ajaxCallback
   */
  public function testAjaxCallback() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    
    $result = $this->form->ajaxCallback($form, $form_state);
    $this->assertInstanceOf(AjaxResponse::class, $result);
  }
}
