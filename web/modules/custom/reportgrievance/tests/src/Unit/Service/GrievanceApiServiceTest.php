<?php

namespace Drupal\Tests\reportgrievance\Unit\Service;

use Drupal\reportgrievance\Service\GrievanceApiService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Access\CsrfTokenGenerator;

/**
 * @coversDefaultClass \Drupal\reportgrievance\Service\GrievanceApiService
 * @group reportgrievance
 */
class GrievanceApiServiceTest extends UnitTestCase {

  protected $httpClient;
  protected $globalVariablesService;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $csrfToken;
  protected $service;

  /**
   * {@inheritdoc}
   * @covers ::__construct
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->globalVariablesService = $this->createMock(GlobalVariablesService::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);
    $this->csrfToken = $this->createMock(CsrfTokenGenerator::class);

    $container = new ContainerBuilder();
    $container->set('csrf_token', $this->csrfToken);
    
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));
    $container->set('logger.factory', $logger_factory);
    
    \Drupal::setContainer($container);

    $this->service = new GrievanceApiService(
      $this->httpClient,
      $this->globalVariablesService,
      $this->vaultConfigService,
      $this->apimanTokenService
    );

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => [
        'config' => [
          'apiUrl' => 'https://api.example.com/',
          'apiVersion' => '/v1/',
        ],
      ],
      'applicationConfig' => [
        'config' => [
          'ceptenantCode' => 'tenant123',
        ],
      ],
    ]);

    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('dummy_token');
  }

  /**
   * @covers ::getIncidentTypes
   */
  public function testGetIncidentTypes() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn(json_encode([
      'data' => [
        ['incidentTypeId' => 1, 'incidentType' => 'Type 1'],
        ['incidentTypeId' => 2, 'incidentType' => 'Type 2'],
      ]
    ]));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', $this->stringContains('master-data/incident-types'))
      ->willReturn($response);

    $result = $this->service->getIncidentTypes();
    $this->assertEquals([1 => 'Type 1', 2 => 'Type 2'], $result);
  }

  /**
   * @covers ::getIncidentSubTypes
   */
  public function testGetIncidentSubTypes() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn(json_encode([
      'data' => [
        ['incidentSubTypeId' => 10, 'incidentSubType' => 'Sub 1'],
      ]
    ]));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('GET', $this->stringContains('master-data/incident-sub-types'))
      ->willReturn($response);

    $result = $this->service->getIncidentSubTypes(1);
    $this->assertEquals([10 => 'Sub 1'], $result);
  }

  /**
   * @covers ::getIncidentSubTypes
   */
  public function testGetIncidentSubTypesNoToken() {
    $apiman = $this->createMock(ApimanTokenService::class);
    $apiman->method('getApimanAccessToken')->willReturn(NULL);
    
    $service = new GrievanceApiService($this->httpClient, $this->globalVariablesService, $this->vaultConfigService, $apiman);
    $result = $service->getIncidentSubTypes(1);
    $this->assertEquals([], $result);
  }

  /**
   * @covers ::sendGrievance
   * @covers ::generateChecksum
   * @covers ::getCsrfToken
   */
  public function testSendGrievanceSuccess() {
    $this->csrfToken->method('get')->willReturn('csrf123');

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['status' => 'success', 'data' => 'GV-123']));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', $this->stringContains('grievance-manage/report-grievance'))
      ->willReturn($response);

    $result = $this->service->sendGrievance(['test' => 'data']);
    $this->assertTrue($result['success']);
    $this->assertEquals('GV-123', $result['data']['data']);
  }

  /**
   * @covers ::sendGrievance
   */
  public function testSendGrievanceFailure() {
    $this->csrfToken->method('get')->willReturn('csrf123');

    $this->httpClient->method('request')->willThrowException(new \Exception('API Error'));

    $result = $this->service->sendGrievance(['test' => 'data']);
    $this->assertFalse($result['success']);
  }
}
