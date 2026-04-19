<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\login_logout\Service\UserRegistrationExternalService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\UserRegistrationExternalService
 * @group login_logout
 */
class UserRegistrationExternalServiceTest extends UnitTestCase
{
  protected $httpClient;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $loggerFactory;
  protected $messenger;
  protected $service;

  protected function setUp(): void
  {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->service = new UserRegistrationExternalService(
      $this->httpClient,
      $this->vaultConfigService,
      $this->apimanTokenService,
      $this->loggerFactory,
      $this->messenger
    );
  }

  /**
   * @covers ::registerApiUser
   */
  public function testRegisterApiUserSuccess(): void
  {
    $data = [
      'first_name' => 'John',
      'last_name' => 'Doe',
      'mail' => 'john@example.com',
      'country_code' => '+91',
      'mobile' => '1234567890',
    ];

    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('token-123');
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.local/', 'apiVersion' => 'v1/']],
      'applicationConfig' => ['config' => ['ceptenantCode' => 'T1']],
    ]);

    $this->httpClient->expects($this->once())->method('request')->willReturn($this->createMock(ResponseInterface::class));

    $this->assertTrue($this->service->registerApiUser($data));
  }

  /**
   * @covers ::registerApiUser
   */
  public function testRegisterApiUserFailure(): void
  {
    $data = ['mail' => 'test@example.com'];
    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('token');
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.local/', 'apiVersion' => 'v1/']],
      'applicationConfig' => ['config' => ['ceptenantCode' => 'T1']],
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')->willReturn(json_encode(['developerMessage' => 'API Error']));
    $response->method('getBody')->willReturn($stream);
    $exception = new RequestException('fail', $this->createMock(RequestInterface::class), $response);

    $this->httpClient->method('request')->willThrowException($exception);
    $this->messenger->expects($this->once())->method('addError');

    $this->assertFalse($this->service->registerApiUser($data));
  }

  /**
   * @covers ::registerScimUser
   */
  public function testRegisterScimUserSuccess(): void
  {
    $data = [
      'first_name' => 'John',
      'last_name' => 'Doe',
      'mail' => 'john@example.com',
      'mobile' => '1234567890',
    ];

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.local']],
    ]);

    $this->httpClient->expects($this->once())->method('request');

    $this->service->registerScimUser($data, 'password123');
  }

  /**
   * @covers ::registerScimUser
   */
  public function testRegisterScimUserFailure(): void
  {
    $data = ['first_name' => 'John', 'mail' => 'john@example.com'];
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.local']],
    ]);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->with('scim_user')->willReturn($logger);

    $response = $this->createMock(ResponseInterface::class);
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('getContents')->willReturn(json_encode(['detail' => 'Error - SCIM Error']));
    $response->method('getBody')->willReturn($stream);
    $exception = new RequestException('fail', $this->createMock(RequestInterface::class), $response);

    $this->httpClient->method('request')->willThrowException($exception);
    $logger->expects($this->once())->method('error');
    $this->messenger->expects($this->once())->method('addError');

    $this->service->registerScimUser($data, 'password123');
  }
}
