<?php

namespace Drupal\Tests\global_module\Unit\Service;

use Drupal\global_module\Service\ApimanTokenService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;

/**
 * @coversDefaultClass \Drupal\global_module\Service\ApimanTokenService
 * @group global_module
 */
class ApimanTokenServiceTest extends UnitTestCase {

  protected $httpClient;
  protected $cache;
  protected $vaultConfigService;
  protected $logger;
  protected $service;

  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->service = new ApimanTokenService(
      $this->httpClient,
      $this->cache,
      $this->vaultConfigService,
      $this->logger
    );
  }

  /**
   * @covers ::getApimanAccessToken
   * @covers ::getCachedToken
   */
  public function testGetApimanAccessTokenCacheHit() {
    $token_data = [
      'access_token' => 'cached-token',
      'expires_at' => time() + 3600,
    ];
    $cache_obj = (object) ['data' => $token_data];
    $this->cache->method('get')->with(ApimanTokenService::CACHE_ID)->willReturn($cache_obj);

    $result = $this->service->getApimanAccessToken();
    $this->assertEquals('cached-token', $result);
  }

  /**
   * @covers ::getApimanAccessToken
   * @covers ::getApimanConfig
   */
  public function testGetApimanAccessTokenMissingConfig() {
    $this->cache->method('get')->willReturn(NULL);
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([]);
    
    $this->logger->expects($this->once())->method('error')->with('Missing apiManConfig configuration in Vault response.');

    $result = $this->service->getApimanAccessToken();
    $this->assertNull($result);
  }

  /**
   * @covers ::getApimanAccessToken
   * @covers ::fetchAndCacheToken
   * @covers ::buildTokenUrl
   * @covers ::buildRequestOptions
   * @covers ::cacheToken
   */
  public function testGetApimanAccessTokenSuccess() {
    $this->cache->method('get')->willReturn(NULL);

    $vault_data = [
      'apiManConfig' => [
        'config' => [
          'apiUrl' => 'http://api.com/',
          'apiVersion' => '/v1/',
        ],
      ],
    ];
    $this->vaultConfigService->method('getGlobalVariables')->willReturn($vault_data);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $token_payload = [
      'access_token' => 'new-token',
      'expires_in' => 3600,
    ];
    $body->method('getContents')->willReturn(json_encode($token_payload));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->willReturn($response);

    $this->cache->expects($this->once())->method('set');

    $result = $this->service->getApimanAccessToken();
    $this->assertEquals('new-token', $result);
  }

  /**
   * @covers ::fetchAndCacheToken
   */
  public function testGetApimanAccessTokenFailure() {
    $this->cache->method('get')->willReturn(NULL);

    $vault_data = [
      'apiManConfig' => [
        'config' => [
          'apiUrl' => 'http://api.com/',
          'apiVersion' => '/v1/',
        ],
      ],
    ];
    $this->vaultConfigService->method('getGlobalVariables')->willReturn($vault_data);

    $request = $this->createMock(RequestInterface::class);
    $exception = new RequestException('Error', $request);
    $this->httpClient->method('request')->willThrowException($exception);

    $this->logger->expects($this->once())->method('error')->with($this->stringContains('Apiman token fetch failed'));

    $result = $this->service->getApimanAccessToken();
    $this->assertNull($result);
  }

  /**
   * @covers ::cacheToken
   */
  public function testCacheTokenInvalidData() {
    $this->cache->method('get')->willReturn(NULL);
    $vault_data = ['apiManConfig' => ['config' => ['apiUrl' => 'http://api.com/', 'apiVersion' => '/v1/']]];
    $this->vaultConfigService->method('getGlobalVariables')->willReturn($vault_data);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['something' => 'else']));
    $response->method('getBody')->willReturn($body);
    $this->httpClient->method('request')->willReturn($response);

    $result = $this->service->getApimanAccessToken();
    $this->assertNull($result);
  }
}
