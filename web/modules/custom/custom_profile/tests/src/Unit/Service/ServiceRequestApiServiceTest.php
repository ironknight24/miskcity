<?php

namespace Drupal\Tests\custom_profile\Unit\Service;

use Drupal\custom_profile\Service\ServiceRequestApiService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

/**
 * @coversDefaultClass \Drupal\custom_profile\Service\ServiceRequestApiService
 * @group custom_profile
 */
class ServiceRequestApiServiceTest extends UnitTestCase {

  protected $httpClient;
  protected $loggerFactory;
  protected $logger;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $service;

  /**
   * {@inheritdoc}
   * @covers ::__construct
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->willReturn($this->logger);
    
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);

    $this->service = new ServiceRequestApiService(
      $this->httpClient,
      $this->loggerFactory,
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

    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('token');
  }

  /**
   * @covers ::getServiceRequestDetails
   * @covers ::getApiUrl
   * @covers ::getHeaders
   * @covers ::executeGetRequest
   */
  public function testGetServiceRequestDetailsSuccess() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn(json_encode(['id' => 'GV-123']));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', 'https://api.example.com/trinityengage-casemanagementsystemv1/common/service-request-by-grievance?grievanceId=GV-123&requestTypeId=1', [
        'headers' => [
          'Authorization' => 'Bearer token',
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
      ])
      ->willReturn($response);

    $result = $this->service->getServiceRequestDetails('GV-123', 1);
    $this->assertEquals(['id' => 'GV-123'], $result);
  }

  /**
   * @covers ::getServiceRequestDetails
   * @covers ::executeGetRequest
   */
  public function testGetServiceRequestDetailsInvalidJson() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn('invalid-json');
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willReturn($response);

    $result = $this->service->getServiceRequestDetails('GV-123', 1);
    $this->assertEquals([], $result);
  }

  /**
   * @covers ::getServiceRequestDetails
   * @covers ::executeGetRequest
   */
  public function testGetServiceRequestDetailsFailure() {
    $this->httpClient->method('request')->willThrowException(new \Exception('Network Error'));
    $this->logger->expects($this->once())->method('error');

    $result = $this->service->getServiceRequestDetails('GV-123', 1);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::getServiceRequests
   * @covers ::getApiUrl
   * @covers ::getHeaders
   * @covers ::executePostRequest
   */
  public function testGetServiceRequestsSuccess() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn(json_encode(['items' => ['SR-1', 'SR-2']]));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', $this->stringContains('common/service-request'), [
        'headers' => [
          'Authorization' => 'Bearer token',
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'json' => [
          'tenantCode' => 'fireppr',
          'search' => 'test-search',
          'pageNumber' => 2,
          'itemsPerPage' => 5,
          'userId' => 123,
          'requestTypeId' => 1,
          'orderBy' => '1',
          'orderByfield' => '1',
        ],
      ])
      ->willReturn($response);

    $result = $this->service->getServiceRequests(123, 2, 5, 'test-search');
    $this->assertEquals(['items' => ['SR-1', 'SR-2']], $result);
  }

  /**
   * @covers ::getServiceRequests
   * @covers ::executePostRequest
   */
  public function testGetServiceRequestsInvalidJson() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn('invalid-json');
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willReturn($response);

    $result = $this->service->getServiceRequests(123);
    $this->assertEquals([], $result);
  }

  /**
   * @covers ::getServiceRequestDetails
   * @covers ::executeGetRequest
   */
  public function testGetServiceRequestDetailsEmptyResponse() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn('null');
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willReturn($response);

    $result = $this->service->getServiceRequestDetails('GV-123', 1);
    $this->assertEquals([], $result);
  }

  /**
   * @covers ::getServiceRequests
   * @covers ::executePostRequest
   */
  public function testGetServiceRequestsEmptyResponse() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn('null');
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->willReturn($response);

    $result = $this->service->getServiceRequests(123);
    $this->assertEquals([], $result);
  }

  /**
   * @covers ::executePostRequest
   */
  public function testExecutePostRequestException() {
    $this->httpClient->method('request')->willThrowException(new \Exception('POST Error'));
    $this->logger->expects($this->once())->method('error')->with($this->stringContains('Exception while making POST request'));

    $reflection = new \ReflectionClass(ServiceRequestApiService::class);
    $method = $reflection->getMethod('executePostRequest');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->service, 'http://example.com', []);
    $this->assertEquals([], $result);
  }
}
