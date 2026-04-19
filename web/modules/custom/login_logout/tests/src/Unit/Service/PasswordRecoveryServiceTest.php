<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\login_logout\Service\PasswordRecoveryService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\PasswordRecoveryService
 * @group login_logout
 */
class PasswordRecoveryServiceTest extends UnitTestCase {

  protected $httpClient;
  protected $vaultConfigService;
  protected $logger;
  protected $service;

  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->service = new PasswordRecoveryService(
      $this->httpClient,
      $this->vaultConfigService,
      $this->logger
    );

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.com']]
    ]);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(PasswordRecoveryService::class, $this->service);
  }

  /**
   * @covers ::get_scim_username_by_email
   */
  public function testGetScimUsernameSuccess() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode([
      'Resources' => [['userName' => 'john_doe']]
    ]));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->with('GET', $this->stringContains('scim2/Users'))->willReturn($response);

    $this->assertEquals('john_doe', $this->service->get_scim_username_by_email('test@test.com'));
  }

  /**
   * @covers ::get_scim_username_by_email
   */
  public function testGetScimUsernameEmpty() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['Resources' => []]));
    $response->method('getBody')->willReturn($body);
    $this->httpClient->method('request')->willReturn($response);

    $this->assertNull($this->service->get_scim_username_by_email('test@test.com'));
  }

  /**
   * @covers ::get_scim_username_by_email
   */
  public function testGetScimUsernameException() {
    $this->httpClient->method('request')->willThrowException(new \Exception('API Error'));
    $this->logger->expects($this->once())->method('error');
    $this->assertNull($this->service->get_scim_username_by_email('test@test.com'));
  }

  /**
   * @covers ::initiateRecovery
   */
  public function testInitiateRecoverySuccess() {
    // Mock get_scim_username_by_email internal call (requires mocking httpClient again)
    $resp1 = $this->createMock(ResponseInterface::class);
    $body1 = $this->createMock(StreamInterface::class);
    $body1->method('getContents')->willReturn(json_encode(['Resources' => [['userName' => 'u']]]));
    $resp1->method('getBody')->willReturn($body1);

    $resp2 = $this->createMock(ResponseInterface::class);
    $body2 = $this->createMock(StreamInterface::class);
    $body2->method('getContents')->willReturn(json_encode([
      ['channelInfo' => ['recoveryCode' => 'code123']]
    ]));
    $resp2->method('getBody')->willReturn($body2);

    $this->httpClient->expects($this->exactly(2))->method('request')
      ->willReturnOnConsecutiveCalls($resp1, $resp2);

    $this->assertEquals('code123', $this->service->initiateRecovery('test@test.com'));
  }

  /**
   * @covers ::initiateRecovery
   */
  public function testInitiateRecoveryEmpty() {
    $resp1 = $this->createMock(ResponseInterface::class);
    $body1 = $this->createMock(StreamInterface::class);
    $body1->method('getContents')->willReturn(json_encode(['Resources' => [['userName' => 'u']]]));
    $resp1->method('getBody')->willReturn($body1);

    $resp2 = $this->createMock(ResponseInterface::class);
    $body2 = $this->createMock(StreamInterface::class);
    $body2->method('getContents')->willReturn(json_encode([]));
    $resp2->method('getBody')->willReturn($body2);

    $this->httpClient->method('request')->willReturnOnConsecutiveCalls($resp1, $resp2);
    $this->logger->expects($this->once())->method('warning');

    $this->assertNull($this->service->initiateRecovery('test@test.com'));
  }

  /**
   * @covers ::initiateRecovery
   */
  public function testInitiateRecoveryRequestException() {
    $resp1 = $this->createMock(ResponseInterface::class);
    $body1 = $this->createMock(StreamInterface::class);
    $body1->method('getContents')->willReturn(json_encode(['Resources' => [['userName' => 'u']]]));
    $resp1->method('getBody')->willReturn($body1);

    $this->httpClient->method('request')->willReturnOnConsecutiveCalls(
      $resp1,
      $this->throwException($this->createMock(RequestException::class))
    );
    $this->logger->expects($this->once())->method('error');

    $this->assertNull($this->service->initiateRecovery('test@test.com'));
  }

  /**
   * @covers ::completeRecovery
   */
  public function testCompleteRecoverySuccess() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['status' => 'ok']));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->willReturn($response);
    $this->logger->expects($this->once())->method('info');

    $result = $this->service->completeRecovery('code');
    $this->assertEquals(['status' => 'ok'], $result);
  }

  /**
   * @covers ::completeRecovery
   */
  public function testCompleteRecoveryException() {
    $this->httpClient->method('request')->willThrowException($this->createMock(RequestException::class));
    $this->logger->expects($this->once())->method('error');

    $this->assertNull($this->service->completeRecovery('code'));
  }

  /**
   * @covers ::recoverPassword
   */
  public function testRecoverPasswordSuccess() {
    $resp1 = $this->createMock(ResponseInterface::class);
    $body1 = $this->createMock(StreamInterface::class);
    $body1->method('getContents')->willReturn(json_encode(['Resources' => [['userName' => 'u']]]));
    $resp1->method('getBody')->willReturn($body1);

    $resp2 = $this->createMock(ResponseInterface::class);
    $body2 = $this->createMock(StreamInterface::class);
    $body2->method('getContents')->willReturn(json_encode([['channelInfo' => ['recoveryCode' => 'c']]]));
    $resp2->method('getBody')->willReturn($body2);

    $resp3 = $this->createMock(ResponseInterface::class);
    $body3 = $this->createMock(StreamInterface::class);
    $body3->method('getContents')->willReturn(json_encode(['final' => 'success']));
    $resp3->method('getBody')->willReturn($body3);

    $this->httpClient->method('request')->willReturnOnConsecutiveCalls($resp1, $resp2, $resp3);

    $result = $this->service->recoverPassword('test@test.com');
    $this->assertEquals(['final' => 'success'], $result);
  }

  /**
   * @covers ::recoverPassword
   */
  public function testRecoverPasswordInitiateFail() {
    $resp1 = $this->createMock(ResponseInterface::class);
    $body1 = $this->createMock(StreamInterface::class);
    $body1->method('getContents')->willReturn(json_encode(['Resources' => [['userName' => 'u']]]));
    $resp1->method('getBody')->willReturn($body1);

    $resp2 = $this->createMock(ResponseInterface::class);
    $body2 = $this->createMock(StreamInterface::class);
    $body2->method('getContents')->willReturn(json_encode([]));
    $resp2->method('getBody')->willReturn($body2);

    $this->httpClient->method('request')->willReturnOnConsecutiveCalls($resp1, $resp2);
    // Called once in initiateRecovery and once in recoverPassword
    $this->logger->expects($this->exactly(2))->method('warning');

    $this->assertNull($this->service->recoverPassword('test@test.com'));
  }

  /**
   * @covers ::recoverPassword
   */
  public function testRecoverPasswordException() {
    // Force exception in initiateRecovery call inside recoverPassword
    $this->vaultConfigService->method('getGlobalVariables')->willThrowException(new \Exception('Process Error'));
    // Called once in get_scim_username_by_email and once in recoverPassword
    $this->logger->expects($this->exactly(2))->method('error');

    $this->assertNull($this->service->recoverPassword('test@test.com'));
  }
}
