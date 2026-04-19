<?php

namespace Drupal\Tests\custom_profile\Unit\Service;

use Drupal\custom_profile\Service\ProfileService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\custom_profile\Service\ProfileService
 * @group custom_profile
 */
class ProfileServiceTest extends UnitTestCase {

  protected $httpClient;
  protected $globalVariablesService;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $service;

  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->globalVariablesService = $this->createMock(GlobalVariablesService::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);

    $this->service = new ProfileService(
      $this->httpClient,
      $this->globalVariablesService,
      $this->vaultConfigService,
      $this->apimanTokenService
    );

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => [
        'config' => [
          'apiUrl' => 'https://api.example.com/',
          'apiVersion' => 'v1/',
        ],
      ],
    ]);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = new ContainerBuilder();
    $container->set('http_client', $this->httpClient);
    $container->set('global_module.global_variables', $this->globalVariablesService);
    $container->set('global_module.vault_config_service', $this->vaultConfigService);
    $container->set('global_module.apiman_token_service', $this->apimanTokenService);

    $service = ProfileService::create($container);
    $this->assertInstanceOf(ProfileService::class, $service);
  }

  /**
   * @covers ::fetchFamilyMembers
   */
  public function testFetchFamilyMembersSuccess() {
    $user_id = 123;
    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('token');

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    
    $raw_data = [
      'data' => [
        [
          'email' => 'encrypted_email',
          'contact' => 'encrypted_contact',
        ],
      ],
    ];
    $body->method('getContents')->willReturn(json_encode($raw_data));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->willReturn($response);

    // Mock decryption and masking
    $this->globalVariablesService->method('decrypt')->willReturnMap([
      ['encrypted_email', 'testuser@example.com'],
      ['encrypted_contact', '1234567890'],
    ]);

    // Mock logger
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);
    
    $container = new ContainerBuilder();
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);

    $result = $this->service->fetchFamilyMembers($user_id);

    $this->assertCount(1, $result);
    $this->assertEquals('tes****@example.com', $result[0]['email']);
    $this->assertEquals('******7890', $result[0]['contact']);
  }

  /**
   * @covers ::fetchFamilyMembers
   */
  public function testFetchFamilyMembersFailure() {
    $requestException = $this->createMock(RequestException::class);
    $this->httpClient->method('request')->willThrowException($requestException);

    // Mock logger
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);
    
    $container = new ContainerBuilder();
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);

    $result = $this->service->fetchFamilyMembers(123);
    $this->assertEmpty($result);
  }
}
