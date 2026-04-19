<?php

namespace Drupal\Tests\global_module\Unit\Service;

use Drupal\global_module\Service\VaultConfigService;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @coversDefaultClass \Drupal\global_module\Service\VaultConfigService
 * @group global_module
 */
class VaultConfigServiceTest extends UnitTestCase {

  protected $httpClient;
  protected $cache;
  protected $lock;
  protected $logger;
  protected $service;

  /**
   * {@inheritdoc}
   * @covers ::__construct
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    new Settings([
      'vault_url' => 'https://vault.example.com',
      'vault_token' => 'test-token',
    ]);

    $this->service = new VaultConfigService(
      $this->httpClient,
      $this->cache,
      $this->lock,
      $this->logger
    );
  }

  /**
   * @covers ::getGlobalVariables
   * @covers ::getFromCache
   */
  public function testGetGlobalVariablesCacheHit() {
    $data = ['key' => 'value'];
    $cache_obj = (object) ['data' => $data];
    $this->cache->method('get')->with(VaultConfigService::CACHE_ID)->willReturn($cache_obj);

    $result = $this->service->getGlobalVariables();
    $this->assertEquals($data, $result);
  }

  /**
   * @covers ::getGlobalVariables
   * @covers ::fetchAndCacheVaultData
   * @covers ::fetchFromVault
   * @covers ::normalizeVaultData
   * @covers ::storeInCache
   * @covers ::acquireLock
   * @covers ::releaseLock
   */
  public function testGetGlobalVariablesSuccess() {
    $this->cache->method('get')->willReturn(NULL);
    $this->lock->method('acquire')->willReturn(TRUE);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $vault_payload = [
      'data' => [
        'applicationConfig' => [
          'config' => [
            'webportalUrl' => 'http://portal.com',
            'siteUrl' => 'http://site.com',
          ],
        ],
      ],
    ];
    $body->method('getContents')->willReturn(json_encode($vault_payload));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->willReturn($response);

    $this->cache->expects($this->once())->method('set');
    $this->lock->expects($this->once())->method('release');

    $result = $this->service->getGlobalVariables();
    $this->assertEquals('http://portal.com', $result['webportalUrl']);
    $this->assertEquals('http://site.com', $result['siteUrl']);
  }

  /**
   * @covers ::getGlobalVariables
   * @covers ::acquireLock
   */
  public function testGetGlobalVariablesLockFailure() {
    $this->cache->method('get')->willReturn(NULL);
    $this->lock->method('acquire')->willReturn(FALSE);

    $result = $this->service->getGlobalVariables();
    $this->assertNull($result);
  }

  /**
   * @covers ::getGlobalVariables
   * @covers ::fetchAndCacheVaultData
   * @covers ::logError
   */
  public function testGetGlobalVariablesVaultError() {
    $this->cache->method('get')->willReturn(NULL);
    $this->lock->method('acquire')->willReturn(TRUE);
    
    $this->httpClient->method('request')->willThrowException(new \Exception('Vault Error'));
    $this->logger->expects($this->once())->method('error')->with($this->stringContains('Vault fetch failed'));

    $result = $this->service->getGlobalVariables();
    $this->assertNull($result);
  }

  /**
   * @covers ::fetchFromVault
   */
  public function testFetchFromVaultMissingSettings() {
    new Settings([]);
    $this->cache->method('get')->willReturn(NULL);
    $this->lock->method('acquire')->willReturn(TRUE);
    
    $this->logger->expects($this->once())->method('error')->with('Vault URL or token missing in settings.php');

    $result = $this->service->getGlobalVariables();
    $this->assertNull($result);
  }

  /**
   * @covers ::fetchAndCacheVaultData
   * @covers ::fetchFromVault
   */
  public function testFetchAndCacheVaultDataNullResponse() {
    $this->cache->method('get')->willReturn(NULL);
    $this->lock->method('acquire')->willReturn(TRUE);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    // Payload with no 'data' key
    $body->method('getContents')->willReturn(json_encode(['foo' => 'bar']));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->willReturn($response);

    $result = $this->service->getGlobalVariables();
    $this->assertNull($result);
  }

  /**
   * @covers ::normalizeVaultData
   */
  public function testNormalizeVaultDataEmptyConfig() {
    $this->cache->method('get')->willReturn(NULL);
    $this->lock->method('acquire')->willReturn(TRUE);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $vault_payload = [
      'data' => [
        // Missing applicationConfig
      ],
    ];
    $body->method('getContents')->willReturn(json_encode($vault_payload));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->willReturn($response);

    $result = $this->service->getGlobalVariables();
    $this->assertEquals('', $result['webportalUrl']);
    $this->assertEquals('', $result['siteUrl']);
  }
}
