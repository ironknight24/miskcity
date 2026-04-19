<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\login_logout\Service\RegistrationAuditService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\RegistrationAuditService
 * @group login_logout
 */
class RegistrationAuditServiceTest extends UnitTestCase
{
  protected $loggerFactory;
  protected $currentUser;
  protected $requestStack;
  protected $service;

  protected function setUp(): void
  {
    parent::setUp();

    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);

    $this->service = new RegistrationAuditService(
      $this->loggerFactory,
      $this->currentUser,
      $this->requestStack
    );
  }

  /**
   * @covers ::logUsernameAnomalies
   * @covers ::buildAuditContext
   * @covers ::getClientIp
   */
  public function testLogUsernameAnomalies(): void
  {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->with('secaudit')->willReturn($logger);

    $request = new Request();
    $request->headers->set('x-real-ip', '1.2.3.4');
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->currentUser->method('id')->willReturn(123);

    // AE4 (length < 5) and AE6 (invalid email) should be logged for 'bad'
    $logger->expects($this->exactly(2))->method('warning');
    $this->service->logUsernameAnomalies('bad');
  }

  /**
   * @covers ::logPasswordAnomalies
   */
  public function testLogPasswordAnomalies(): void
  {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->with('secaudit')->willReturn($logger);

    $request = new Request();
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    // AE5 and AE7
    $logger->expects($this->exactly(2))->method('warning');
    $this->service->logPasswordAnomalies("\x01short");
  }
}
