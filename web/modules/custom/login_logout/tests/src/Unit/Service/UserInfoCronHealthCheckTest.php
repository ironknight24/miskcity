<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\login_logout\Service\UserInfoCronHealthCheck;
use Drupal\login_logout\Service\UserInfoValidator;
use Psr\Log\LoggerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\UserInfoCronHealthCheck
 * @group login_logout
 */
class UserInfoCronHealthCheckTest extends UnitTestCase {

  protected $validator;
  protected $logger;
  protected $service;

  protected function setUp(): void {
    parent::setUp();
    $this->validator = $this->createMock(UserInfoValidator::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->service = new UserInfoCronHealthCheck($this->validator, $this->logger);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(UserInfoCronHealthCheck::class, $this->service);
  }

  /**
   * @covers ::run
   */
  public function testRunSuccess() {
    $this->validator->method('validate')->willReturn(['sub' => 'test@example.com']);
    
    // Expect two info logs: "Running..." and "Cron userinfo check passed..."
    $this->logger->expects($this->exactly(2))->method('info');
    $this->logger->expects($this->never())->method('warning');

    $this->service->run();
  }

  /**
   * @covers ::run
   */
  public function testRunFailure() {
    $this->validator->method('validate')->willReturn([]);
    
    // Expect one info log: "Running..."
    $this->logger->expects($this->once())->method('info');
    // Expect one warning log: "Cron userinfo check failed."
    $this->logger->expects($this->once())->method('warning');

    $this->service->run();
  }
}
