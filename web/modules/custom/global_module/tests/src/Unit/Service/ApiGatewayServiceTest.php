<?php

namespace Drupal\Tests\global_module\Unit\Service;

use Drupal\global_module\Service\ApiGatewayService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApiHttpClientService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * @coversDefaultClass \Drupal\global_module\Service\ApiGatewayService
 * @group global_module
 */
class ApiGatewayServiceTest extends UnitTestCase {

  protected $vaultConfigService;
  protected $apiHttpClientService;
  protected $service;

  /**
   * {@inheritdoc}
   * @covers ::__construct
   */
  protected function setUp(): void {
    parent::setUp();

    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apiHttpClientService = $this->createMock(ApiHttpClientService::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(\Drupal\Core\Logger\LoggerChannelInterface::class));
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);

    $this->service = new ApiGatewayService(
      $this->vaultConfigService,
      $this->apiHttpClientService
    );

    $vault_data = [
      'apiManConfig' => [
        'config' => [
          'apiUrl' => 'http://api.com/',
          'apiVersion' => '/v1/',
        ],
      ],
      'applicationConfig' => [
        'config' => [
          'webportalUrl' => 'http://portal.com',
          'deleteAPICA' => 'http://delete.com/',
        ],
      ],
    ];
    $this->vaultConfigService->method('getGlobalVariables')->willReturn($vault_data);
  }

  /**
   * @covers ::getServiceUrl
   */
  public function testGetServiceUrl() {
    $this->assertEquals('http://portal.com', $this->service->getServiceUrl('portal'));
    $this->assertEquals('http://api.com/UMA/v1/', $this->service->getServiceUrl('idam'));
    $this->assertEquals('', $this->service->getServiceUrl('unknown'));
  }

  /**
   * @covers ::postData
   * @covers ::buildServiceUrl
   * @covers ::handleRequestByType
   */
  public function testPostDataSuccess() {
    $request = Request::create('/post', 'POST', [], [], [], [], json_encode([
      'service' => 'idam',
      'type' => 2,
      'payloads' => ['foo' => 'bar'],
    ]));
    
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);

    $this->apiHttpClientService->method('postApiman')->willReturn(['status' => TRUE]);

    $response = $this->service->postData($request);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals(json_encode(['status' => TRUE]), $response->getContent());
  }

  /**
   * @covers ::postData
   */
  public function testPostDataMethodNotAllowed() {
    $request = Request::create('/post', 'GET');
    $response = $this->service->postData($request);
    $this->assertEquals(405, $response->getStatusCode());
  }

  /**
   * @covers ::postData
   */
  public function testPostDataInvalidPayload() {
    $request = Request::create('/post', 'POST', [], [], [], [], json_encode(['foo' => 'bar']));
    $response = $this->service->postData($request);
    $this->assertEquals(400, $response->getStatusCode());
  }

  /**
   * @covers ::handleRequestByType
   */
  public function testHandleRequestByTypeDefault() {
    $request = new Request();
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);

    $this->apiHttpClientService->expects($this->once())->method('postApiman')->willReturn(['status' => TRUE]);

    $result = $this->service->handleRequestByType(['type' => 'unknown', 'payloads' => []], 'http://url', $request);
    $this->assertTrue($result['status']);
  }

  /**
   * @covers ::handleRequestByType
   */
  public function testHandleRequestByTypeDelyUser() {
    $request = new Request();
    $session = $this->createMock(SessionInterface::class);
    $session->method('get')->with('api_redirect_result')->willReturn(['userId' => 123, 'tenantCode' => 'T1']);
    $request->setSession($session);

    // Mock dependencies for userDelete
    $this->apiHttpClientService->method('postApi')->willReturn(['status' => TRUE]);
    $this->apiHttpClientService->method('postApiman')->willReturn(['status' => TRUE]);

    $container = \Drupal::getContainer();
    $current_user = $this->createMock(\Drupal\Core\Session\AccountProxyInterface::class);
    $current_user->method('id')->willReturn(123);
    $container->set('current_user', $current_user);
    
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user = $this->getMockBuilder(User::class)->disableOriginalConstructor()->getMock();
    $user_storage->method('load')->willReturn($user);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($user_storage);
    $container->set('entity_type.manager', $entity_type_manager);
    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('user');
    $container->set('entity_type.repository', $entity_type_repository);

    $result = $this->service->handleRequestByType(['type' => 'delyUser'], 'http://url', $request);
    $this->assertTrue($result['status']);
  }

  /**
   * @covers ::userDelete
   * @covers ::deleteFromCityApp
   * @covers ::deleteFromCEP
   * @covers ::deleteDrupalAccount
   */
  public function testUserDeleteSuccess() {
    $this->apiHttpClientService->method('postApi')->willReturn(['status' => TRUE]);
    $this->apiHttpClientService->method('postApiman')->willReturn(['status' => TRUE]);

    $container = \Drupal::getContainer();
    $current_user = $this->createMock(\Drupal\Core\Session\AccountProxyInterface::class);
    $current_user->method('id')->willReturn(123);
    $container->set('current_user', $current_user);
    
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user = $this->getMockBuilder(User::class)->disableOriginalConstructor()->getMock();
    $user->expects($this->once())->method('delete');
    $user_storage->method('load')->willReturn($user);
    
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($user_storage);
    $container->set('entity_type.manager', $entity_type_manager);
    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('user');
    $container->set('entity_type.repository', $entity_type_repository);

    $result = $this->service->userDelete(123, 'tenant');
    $this->assertTrue($result['status']);
    $this->assertEquals('User account deleted successfully!', $result['message']);
  }

  /**
   * @covers ::userDelete
   * @covers ::deleteFromCityApp
   */
  public function testUserDeleteFailureCityApp() {
    $this->apiHttpClientService->method('postApi')->willReturn(['status' => FALSE]);
    
    $result = $this->service->userDelete(123, 'tenant');
    $this->assertFalse($result['status']);
    $this->assertEquals('Failed to delete user account.', $result['message']);
  }

  /**
   * @covers ::userDelete
   * @covers ::deleteFromCityApp
   * @covers ::deleteFromCEP
   */
  public function testUserDeleteFailureCEP() {
    $this->apiHttpClientService->method('postApi')->willReturn(['status' => TRUE]);
    $this->apiHttpClientService->method('postApiman')->willReturn(['status' => FALSE]);
    
    $result = $this->service->userDelete(123, 'tenant');
    $this->assertFalse($result['status']);
    $this->assertEquals('Failed to delete user from case management system.', $result['message']);
  }

  /**
   * @covers ::userDelete
   * @covers ::deleteFromCityApp
   * @covers ::deleteFromCEP
   * @covers ::deleteDrupalAccount
   */
  public function testUserDeleteDrupalUserNotFound() {
    $this->apiHttpClientService->method('postApi')->willReturn(['status' => TRUE]);
    $this->apiHttpClientService->method('postApiman')->willReturn(['status' => TRUE]);

    $container = \Drupal::getContainer();
    $current_user = $this->createMock(\Drupal\Core\Session\AccountProxyInterface::class);
    $current_user->method('id')->willReturn(123);
    $container->set('current_user', $current_user);
    
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('load')->willReturn(NULL);
    
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($user_storage);
    $container->set('entity_type.manager', $entity_type_manager);
    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('user');
    $container->set('entity_type.repository', $entity_type_repository);

    $result = $this->service->userDelete(123, 'tenant');
    $this->assertTrue($result['status']);
  }
}
