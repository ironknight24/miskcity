<?php

namespace Drupal\Tests\custom_profile\Unit\Form;

use Drupal\custom_profile\Form\AddFamilyMemberForm;
use Drupal\global_module\Service\FileUploadService;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\custom_profile\Form\AddFamilyMemberForm
 * @group custom_profile
 */
class AddFamilyMemberFormTest extends UnitTestCase {

  protected $fileUploadService;
  protected $httpClient;
  protected $requestStack;
  protected $globalVariableService;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $form;

  protected function setUp(): void {
    parent::setUp();

    $this->fileUploadService = $this->createMock(FileUploadService::class);
    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->globalVariableService = $this->createMock(GlobalVariablesService::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);

    $request = $this->createMock(Request::class);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('global_module.file_upload_service', $this->fileUploadService);
    $container->set('http_client', $this->httpClient);
    $container->set('request_stack', $this->requestStack);
    $container->set('global_module.global_variables', $this->globalVariableService);
    $container->set('global_module.vault_config_service', $this->vaultConfigService);
    $container->set('global_module.apiman_token_service', $this->apimanTokenService);

    \Drupal::setContainer($container);

    $this->form = new class(
      $this->fileUploadService,
      $this->httpClient,
      $this->requestStack,
      $this->globalVariableService,
      $this->vaultConfigService,
      $this->apimanTokenService
    ) extends AddFamilyMemberForm {
      public function buildContainerProxy(array $form): array {
        return $this->buildFormContainer($form);
      }

      public function identityFieldsProxy(array $form, FormStateInterface $form_state): array {
        return $this->addIdentityFields($form, $form_state);
      }

      public function relationshipFieldsProxy(array $form): array {
        return $this->addRelationshipFields($form);
      }

      public function contactFieldsProxy(array $form): array {
        return $this->addContactFields($form);
      }

      public function uploadTermsProxy(array $form): array {
        return $this->addUploadAndTermsFields($form);
      }

      public function actionFieldsProxy(array $form): array {
        return $this->addActionFields($form);
      }

      public function finalizeProxy(array $form): array {
        return $this->finalizeForm($form);
      }
    };
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('add_family_member_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertEquals('add-family-member', $built_form['#theme']);
    $this->assertArrayHasKey('first_name', $built_form);
    $this->assertArrayHasKey('calendar', $built_form);
    $this->assertArrayHasKey('gender', $built_form);
    $this->assertArrayHasKey('relations', $built_form);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValues')->willReturn([
      'first_name' => 'Name',
      'calendar' => '1990-01-01',
      'gender' => 'Male',
      'relations' => 'Son',
      'phone_number' => '1234567890',
      'email' => 'test@test.com',
    ]);

    $request = \Drupal::request();
    $session = $this->createMock(SessionInterface::class);
    $request->method('getSession')->willReturn($session);

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.com/', 'apiVersion' => '/v1/']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['status' => TRUE]));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->willReturn($response);

    // Mock logger
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);
    \Drupal::getContainer()->set('logger.factory', $logger_factory);

    $this->form->submitForm($form, $form_state);
  }

  /**
   * @covers ::buildFormContainer
   * @covers ::addIdentityFields
   * @covers ::addRelationshipFields
   * @covers ::addContactFields
   * @covers ::addUploadAndTermsFields
   * @covers ::addActionFields
   * @covers ::finalizeForm
   */
  public function testBuildFormHelpers() {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getErrors')->willReturn([]);

    $form = $this->form->buildContainerProxy([]);
    $form = $this->form->identityFieldsProxy($form, $form_state);
    $form = $this->form->relationshipFieldsProxy($form);
    $form = $this->form->contactFieldsProxy($form);
    $form = $this->form->uploadTermsProxy($form);
    $form = $this->form->actionFieldsProxy($form);
    $form = $this->form->finalizeProxy($form);

    $this->assertArrayHasKey('user_id', $form);
    $this->assertArrayHasKey('first_name', $form);
    $this->assertArrayHasKey('calendar', $form);
    $this->assertArrayHasKey('gender', $form);
    $this->assertArrayHasKey('relations', $form);
    $this->assertArrayHasKey('phone_number', $form);
    $this->assertArrayHasKey('email', $form);
    $this->assertArrayHasKey('upload_file', $form);
    $this->assertArrayHasKey('terms', $form);
    $this->assertArrayHasKey('submit', $form['actions']);
    $this->assertArrayHasKey('cancel', $form['actions']);
    $this->assertSame('add-family-member', $form['#theme']);
  }
}
