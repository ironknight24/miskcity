<?php

namespace Drupal\Tests\login_logout\Unit\Controller;

use Drupal\login_logout\Controller\PasswordRecoveryStatusController;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @coversDefaultClass \Drupal\login_logout\Controller\PasswordRecoveryStatusController
 * @group login_logout
 */
class PasswordRecoveryStatusControllerTest extends UnitTestCase {

  protected $tempStoreFactory;
  protected $tempStore;
  protected $controller;

  protected function setUp(): void {
    parent::setUp();

    $this->tempStoreFactory = $this->createMock(PrivateTempStoreFactory::class);
    $this->tempStore = $this->createMock(PrivateTempStore::class);

    $this->tempStoreFactory->method('get')->with('login_logout')->willReturn($this->tempStore);

    $this->controller = new PasswordRecoveryStatusController($this->tempStoreFactory);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(PasswordRecoveryStatusController::class, $this->controller);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->with('tempstore.private')->willReturn($this->tempStoreFactory);

    $controller = PasswordRecoveryStatusController::create($container);
    $this->assertInstanceOf(PasswordRecoveryStatusController::class, $controller);
  }

  /**
   * @covers ::statusPage
   */
  public function testStatusPage() {
    $email = 'test@example.com';
    $this->tempStore->method('get')->with('recovery_email')->willReturn($email);

    $build = $this->controller->statusPage();

    $this->assertEquals('password_recovery_status', $build['#theme']);
    $this->assertEquals($email, $build['#email']);
  }

  /**
   * @covers ::statusPage
   */
  public function testStatusPageEmpty() {
    $this->tempStore->method('get')->with('recovery_email')->willReturn(NULL);

    $build = $this->controller->statusPage();

    $this->assertEquals('password_recovery_status', $build['#theme']);
    $this->assertNull($build['#email']);
  }
}
