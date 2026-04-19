<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\login_logout\Service\UserInfoValidator;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\Tests\UnitTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\UserInfoValidator
 * @group login_logout
 */
class UserInfoValidatorTest extends UnitTestCase {

  protected $httpClient;
  protected $logger;
  protected $session;
  protected $globalVariablesService;
  protected $vaultConfigService;
  protected $validator;

  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->session = $this->createMock(SessionInterface::class);
    $this->globalVariablesService = $this->createMock(GlobalVariablesService::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);

    $this->validator = new UserInfoValidator(
      $this->httpClient,
      $this->logger,
      $this->session,
      $this->globalVariablesService,
      $this->vaultConfigService
    );
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(UserInfoValidator::class, $this->validator);
  }

  /**
   * @covers ::validate
   */
  public function testValidateNoToken() {
    $this->session->method('get')->with('login_logout.access_token')->willReturn(NULL);
    $this->logger->expects($this->once())->method('notice')->with('No access token found in session.');

    $result = $this->validator->validate();
    $this->assertNull($result);
  }

  /**
   * @covers ::validate
   */
  public function testValidateSuccess() {
    $this->session->method('get')->with('login_logout.access_token')->willReturn('token123');
    
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.com']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['sub' => 'user123', 'name' => 'John']));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', 'https://idam.com/oauth2/userinfo')
      ->willReturn($response);

    $result = $this->validator->validate();
    $this->assertEquals(['sub' => 'user123', 'name' => 'John'], $result);
  }

  /**
   * @covers ::validate
   */
  public function testValidateNoSub() {
    $this->session->method('get')->with('login_logout.access_token')->willReturn('token123');
    
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.com']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['name' => 'John']));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->willReturn($response);
    $this->logger->expects($this->once())->method('warning')->with($this->stringContains('no sub returned'));

    $result = $this->validator->validate();
    $this->assertNull($result);
  }

  /**
   * @covers ::validate
   */
  public function testValidateException() {
    $this->session->method('get')->with('login_logout.access_token')->willReturn('token123');
    
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.com']]
    ]);

    $this->httpClient->method('request')->willThrowException(new \Exception('Network error'));
    $this->logger->expects($this->once())->method('error')->with($this->stringContains('UserInfo validation error'));

    $result = $this->validator->validate();
    $this->assertNull($result);
  }
}
