<?php

namespace Drupal\Tests\custom_profile\Unit\Form;

use Drupal\custom_profile\Form\ProfilePictureForm;
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

/**
 * @coversDefaultClass \Drupal\custom_profile\Form\ProfilePictureForm
 * @group custom_profile
 */
class ProfilePictureFormTest extends UnitTestCase {

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

    $this->form = new ProfilePictureForm($this->globalVariablesService, $this->vaultConfigService, $this->apimanTokenService);
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('profile_picture_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertArrayHasKey('profile_picture_wrapper', $built_form);
    $this->assertArrayHasKey('upload_file', $built_form);
    $this->assertArrayHasKey('remove', $built_form);
  }

  /**
   * @covers ::ajaxCallback
   */
  public function testAjaxCallback() {
    $form = ['image' => [], 'profilePic_filename' => [], 'profile_picture_wrapper' => []];
    $form_state = $this->createMock(FormStateInterface::class);

    $session = \Drupal::request()->getSession();
    $session->method('get')->with('api_redirect_result')->willReturn([
      'firstName' => 'John',
      'lastName' => 'Doe',
      'tenantCode' => 'tenant',
    ]);

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.com/', 'apiVersion' => '/v1/']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn(json_encode(['status' => TRUE]));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('post')->willReturn($response);

    // Mock logger
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);
    \Drupal::getContainer()->set('logger.factory', $logger_factory);

    $result = $this->form->ajaxCallback($form, $form_state);
    $this->assertIsArray($result);
  }
}
