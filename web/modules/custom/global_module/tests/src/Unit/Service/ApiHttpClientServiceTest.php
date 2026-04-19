<?php

namespace Drupal\Tests\global_module\Unit\Service;

use Drupal\global_module\Service\ApiHttpClientService;
use Drupal\global_module\Service\ApimanTokenService;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;

/**
 * @coversDefaultClass \Drupal\global_module\Service\ApiHttpClientService
 * @group global_module
 */
class ApiHttpClientServiceTest extends UnitTestCase {

  protected $httpClient;
  protected $logger;
  protected $apimanTokenService;
  protected $service;

  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);

    $this->service = new ApiHttpClientService(
      $this->httpClient,
      $this->logger,
      $this->apimanTokenService
    );
  }

  protected function createResponseMock($data) {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode($data));
    $response->method('getBody')->willReturn($body);
    return $response;
  }

  /**
   * @covers ::postApiman
   * @covers ::request
   * @covers ::apimanHeaders
   */
  public function testPostApiman() {
    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('token');
    $this->httpClient->method('request')->willReturn($this->createResponseMock(['status' => 'ok']));

    $result = $this->service->postApiman('http://api.com', ['foo' => 'bar']);
    $this->assertEquals(['status' => 'ok'], $result);
  }

  /**
   * @covers ::deleteApiman
   */
  public function testDeleteApiman() {
    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('token');
    $this->httpClient->method('request')->with('DELETE')->willReturn($this->createResponseMock(['deleted' => true]));

    $result = $this->service->deleteApiman('http://api.com');
    $this->assertEquals(['deleted' => true], $result);
  }

  /**
   * @covers ::postIdam
   */
  public function testPostIdam() {
    $this->httpClient->method('request')->willReturn($this->createResponseMock(['idam' => 'ok']));
    $result = $this->service->postIdam('http://idam.com', ['u' => 'p']);
    $this->assertEquals(['idam' => 'ok'], $result);
  }

  /**
   * @covers ::postIdamAuth
   */
  public function testPostIdamAuth() {
    $this->httpClient->method('request')->willReturn($this->createResponseMock(['auth' => 'granted']));
    $result = $this->service->postIdamAuth('http://idam.com', ['code' => '123']);
    $this->assertEquals(['auth' => 'granted'], $result);
  }

  /**
   * @covers ::postApi
   */
  public function testPostApi() {
    $this->httpClient->method('request')->willReturn($this->createResponseMock(['api' => 'post_ok']));
    $result = $this->service->postApi('http://api.com');
    $this->assertEquals(['api' => 'post_ok'], $result);
  }

  /**
   * @covers ::getApi
   */
  public function testGetApi() {
    $this->httpClient->method('request')->with('GET')->willReturn($this->createResponseMock(['data' => 'info']));
    $result = $this->service->getApi('http://api.com');
    $this->assertEquals(['data' => 'info'], $result);
  }

  /**
   * @covers ::request
   * @covers ::logException
   */
  public function testRequestRequestException() {
    $request = $this->createMock(RequestInterface::class);
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn('Error details');
    $response->method('getBody')->willReturn($body);
    
    $exception = new RequestException('Error', $request, $response);
    $this->httpClient->method('request')->willThrowException($exception);

    $this->logger->expects($this->once())->method('error')->with($this->stringContains('HTTP request failed'), $this->callback(function($ctx) {
        return $ctx['@response'] === 'Error details';
    }));

    $result = $this->service->getApi('http://api.com');
    $this->assertEquals(['error' => 'Request failed'], $result);
  }

  /**
   * @covers ::request
   */
  public function testRequestGenericException() {
    $this->httpClient->method('request')->willThrowException(new \Exception('Fatal'));
    $this->logger->expects($this->once())->method('error');

    $result = $this->service->getApi('http://api.com');
    $this->assertNull($result);
  }
}
