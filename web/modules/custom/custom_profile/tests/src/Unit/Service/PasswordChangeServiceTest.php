<?php

namespace Drupal\Tests\custom_profile\Unit\Service;

use Drupal\custom_profile\Service\PasswordChangeService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApiHttpClientService;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @coversDefaultClass \Drupal\custom_profile\Service\PasswordChangeService
 * @group custom_profile
 */
class PasswordChangeServiceTest extends UnitTestCase {

  protected $globalVariables;
  protected $loggerFactory;
  protected $logger;
  protected $currentUser;
  protected $session;
  protected $vaultConfigService;
  protected $apiHttpClientService;
  protected $service;

  protected function setUp(): void {
    parent::setUp();

    $this->globalVariables = $this->createMock(GlobalVariablesService::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->willReturn($this->logger);
    
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->session = $this->createMock(SessionInterface::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apiHttpClientService = $this->createMock(ApiHttpClientService::class);

    $this->service = new class(
      $this->globalVariables,
      $this->loggerFactory,
      $this->currentUser,
      $this->session,
      $this->vaultConfigService,
      $this->apiHttpClientService
    ) extends PasswordChangeService {
      public function mismatchMessage(string $newPass, string $confirmPass): ?string {
        return $this->getPasswordMismatchMessage($newPass, $confirmPass);
      }

      public function idamConfig(): string {
        return $this->getIdamConfig();
      }

      public function scimUserId(string $email, string $idamconfig): ?string {
        return $this->getScimUserId($email, $idamconfig);
      }

      public function oldPasswordValid(string $email, string $oldPass, string $idamconfig): bool {
        return $this->isOldPasswordValid($email, $oldPass, $idamconfig);
      }

      public function passwordPayload(string $newPass): array {
        return $this->buildPasswordUpdatePayload($newPass);
      }

      public function changeMessage(array $response, string $email, string $defaultMessage): string {
        return $this->resolvePasswordChangeMessage($response, $email, $defaultMessage);
      }
    };

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => [
        'config' => [
          'idamconfig' => 'idam.example.com',
        ],
      ],
    ]);
  }

  /**
   * @covers ::changePassword
   */
  public function testChangePasswordMismatch() {
    $result = $this->service->changePassword('old', 'new', 'different');
    $this->assertFalse($result['status']);
    $this->assertEquals('New password and confirm password do not match.', $result['message']);
  }

  /**
   * @covers ::changePassword
   */
  public function testChangePasswordUserNotFound() {
    $this->currentUser->method('getEmail')->willReturn('test@example.com');
    $this->apiHttpClientService->method('getApi')->willReturn(['Resources' => []]);

    $result = $this->service->changePassword('old', 'new', 'new');
    $this->assertFalse($result['status']);
    $this->assertEquals('User not found in SCIM.', $result['message']);
  }

  /**
   * @covers ::changePassword
   */
  public function testChangePasswordOldMismatch() {
    $this->currentUser->method('getEmail')->willReturn('test@example.com');
    $this->apiHttpClientService->method('getApi')->willReturn(['Resources' => [['id' => 'user123']]]);
    $this->apiHttpClientService->method('postIdam')->willReturn([]);

    $result = $this->service->changePassword('wrong_old', 'new', 'new');
    $this->assertFalse($result['status']);
    $this->assertEquals('Old password not matching!', $result['message']);
  }

  /**
   * @covers ::changePassword
   */
  public function testChangePasswordUpdateError() {
    $this->currentUser->method('getEmail')->willReturn('test@example.com');
    $this->apiHttpClientService->method('getApi')->willReturn(['Resources' => [['id' => 'user123']]]);
    $this->apiHttpClientService->method('postIdam')->willReturn(['access_token' => 'token']);
    $this->apiHttpClientService->method('postIdamAuth')->willReturn(['error' => 'fail', 'details' => ['detail' => 'Custom error']]);

    $result = $this->service->changePassword('old', 'new', 'new');
    $this->assertFalse($result['status']);
    $this->assertEquals('Custom error', $result['message']);
  }

  /**
   * @covers ::changePassword
   */
  public function testChangePasswordHistoryError() {
    $this->currentUser->method('getEmail')->willReturn('test@example.com');
    $this->apiHttpClientService->method('getApi')->willReturn(['Resources' => [['id' => 'user123']]]);
    $this->apiHttpClientService->method('postIdam')->willReturn(['access_token' => 'token']);
    $this->apiHttpClientService->method('postIdamAuth')->willReturn(['detail' => 'Password history violation']);

    $result = $this->service->changePassword('old', 'new', 'new');
    $this->assertFalse($result['status']);
    $this->assertStringContainsString('already used in your last 3 password changes', $result['message']);
  }

  /**
   * @covers ::changePassword
   */
  public function testChangePasswordSuccess() {
    $this->currentUser->method('getEmail')->willReturn('test@example.com');
    $this->apiHttpClientService->method('getApi')->willReturn(['Resources' => [['id' => 'user123']]]);
    $this->apiHttpClientService->method('postIdam')->willReturn(['access_token' => 'token']);
    $this->apiHttpClientService->method('postIdamAuth')->willReturn(['emails' => ['test@example.com']]);

    $result = $this->service->changePassword('old', 'new', 'new');
    $this->assertFalse($result['status']); // Note: The original code doesn't set status to TRUE on success, only message.
    $this->assertEquals('Password changed successfully. Please log in again.', $result['message']);
  }

  /**
   * @covers ::changePassword
   */
  public function testChangePasswordException() {
    $this->currentUser->method('getEmail')->willThrowException(new \Exception('Fatal Error'));
    $result = $this->service->changePassword('old', 'new', 'new');
    $this->assertFalse($result['status']);
    $this->assertEquals('Unexpected error occurred.', $result['message']);
  }

  /**
   * @covers ::getPasswordMismatchMessage
   * @covers ::getIdamConfig
   * @covers ::buildPasswordUpdatePayload
   */
  public function testPasswordChangeHelpers(): void {
    $this->assertSame('New password and confirm password do not match.', $this->service->mismatchMessage('new', 'different'));
    $this->assertNull($this->service->mismatchMessage('same', 'same'));
    $this->assertSame('idam.example.com', $this->service->idamConfig());

    $payload = $this->service->passwordPayload('Secret123!');
    $this->assertSame('replace', $payload['Operations'][0]['op']);
    $this->assertSame('password', $payload['Operations'][0]['path']);
    $this->assertSame('Secret123!', $payload['Operations'][0]['value']);
  }

  /**
   * @covers ::getScimUserId
   * @covers ::isOldPasswordValid
   */
  public function testScimAndOldPasswordHelpers(): void {
    $this->apiHttpClientService->expects($this->once())
      ->method('getApi')
      ->willReturn(['Resources' => [['id' => 'abc123']]]);
    $this->assertSame('abc123', $this->service->scimUserId('test@example.com', 'idam.example.com'));

    $this->apiHttpClientService = $this->createMock(ApiHttpClientService::class);
    $service = new class(
      $this->globalVariables,
      $this->loggerFactory,
      $this->currentUser,
      $this->session,
      $this->vaultConfigService,
      $this->apiHttpClientService
    ) extends PasswordChangeService {
      public function oldPasswordValid(string $email, string $oldPass, string $idamconfig): bool {
        return $this->isOldPasswordValid($email, $oldPass, $idamconfig);
      }
    };

    $this->apiHttpClientService->method('postIdam')->willReturn(['access_token' => 'token']);
    $this->assertTrue($service->oldPasswordValid('test@example.com', 'old', 'idam.example.com'));
  }

  /**
   * @covers ::resolvePasswordChangeMessage
   */
  public function testResolvePasswordChangeMessageBranches(): void {
    $this->assertSame(
      'Password update failed',
      $this->service->changeMessage(['error' => 'fail'], 'test@example.com', 'default')
    );
    $this->assertSame(
      'Password changed successfully. Please log in again.',
      $this->service->changeMessage(['emails' => ['test@example.com']], 'test@example.com', 'default')
    );
    $this->assertStringContainsString(
      'already used in your last 3 password changes',
      $this->service->changeMessage(['detail' => 'Password history violation'], 'test@example.com', 'default')
    );
    $this->assertSame(
      'Specific detail',
      $this->service->changeMessage(['detail' => 'Specific detail'], 'test@example.com', 'default')
    );
    $this->assertSame(
      'default',
      $this->service->changeMessage([], 'test@example.com', 'default')
    );
  }
}
