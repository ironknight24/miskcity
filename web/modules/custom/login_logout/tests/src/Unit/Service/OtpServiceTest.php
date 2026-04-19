<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\login_logout\Service\OtpService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\OtpService
 * @group login_logout
 */
class OtpServiceTest extends UnitTestCase
{
  protected $httpClient;
  protected $vaultConfigService;
  protected $messenger;
  protected $session;
  protected $flood;
  protected $lock;
  protected $database;
  protected $loggerFactory;
  protected $time;
  protected $service;

  protected function setUp(): void
  {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->session = $this->createMock(SessionInterface::class);
    $this->flood = $this->createMock(FloodInterface::class);
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->time = $this->createMock(TimeInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->service = new OtpService(
      $this->httpClient,
      $this->vaultConfigService,
      $this->messenger,
      $this->session,
      $this->flood,
      $this->lock,
      $this->database,
      $this->loggerFactory,
      $this->time
    );
  }

  /**
   * @covers ::generateOtp
   */
  public function testGenerateOtp(): void
  {
    $otp = $this->service->generateOtp();
    $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
  }

  /**
   * @covers ::enforceOtpRateLimit
   * @covers ::buildOtpIdentifier
   * @covers ::getOtpWaitTime
   */
  public function testEnforceOtpRateLimitAllowed(): void
  {
    $this->session->method('getId')->willReturn('session1');
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->assertNull($this->service->enforceOtpRateLimit('test@example.com'));
  }

  /**
   * @covers ::enforceOtpRateLimit
   * @covers ::buildOtpIdentifier
   * @covers ::getOtpWaitTime
   */
  public function testEnforceOtpRateLimitBlocked(): void
  {
    $this->session->method('getId')->willReturn('session1');
    $this->flood->method('isAllowed')->willReturn(FALSE);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(1000);
    $query = $this->createMock(SelectInterface::class);
    $query->method('fields')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('orderBy')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($query);

    $this->time->method('getCurrentTime')->willReturn(1030);

    $response = $this->service->enforceOtpRateLimit('test@example.com');
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(429, $response->getStatusCode());
  }

  /**
   * @covers ::sendOtpWithLock
   * @covers ::sendOtpWebhookRequest
   */
  public function testSendOtpWithLockSuccess(): void
  {
    $data = [
      'first_name' => 'John',
      'last_name' => 'Doe',
      'mail' => 'john@example.com',
      'mobile' => '1234567890',
    ];
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['otpWebhookUrl' => 'http://otp.local']],
    ]);

    $this->httpClient->expects($this->once())->method('request');
    $this->messenger->expects($this->once())->method('addStatus');
    $this->flood->expects($this->once())->method('register');
    $this->lock->expects($this->once())->method('release');

    $this->assertNull($this->service->sendOtpWithLock($data, '123456'));
  }

  /**
   * @covers ::sendOtpWithLock
   */
  public function testSendOtpWithLockAcquireFailure(): void
  {
    $data = ['mail' => 'john@example.com'];
    $this->lock->method('acquire')->willReturn(FALSE);
    $this->messenger->expects($this->once())->method('addError');

    $response = $this->service->sendOtpWithLock($data, '123456');
    $this->assertSame(503, $response->getStatusCode());
  }

  /**
   * @covers ::sendOtpWithLock
   */
  public function testSendOtpWithLockWebhookFailure(): void
  {
    $data = ['mail' => 'john@example.com'];
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['otpWebhookUrl' => 'http://otp.local']],
    ]);
    $this->httpClient->method('request')->willThrowException(new \Exception('fail'));

    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->willReturn($logger);
    $this->messenger->expects($this->once())->method('addError');
    $this->lock->expects($this->once())->method('release');

    $response = $this->service->sendOtpWithLock($data, '123456');
    $this->assertSame(500, $response->getStatusCode());
  }
}
