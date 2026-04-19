<?php

namespace Drupal\Tests\login_logout\Unit\EventSubscriber;

use Drupal\login_logout\EventSubscriber\ForceHttpsSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @coversDefaultClass \Drupal\login_logout\EventSubscriber\ForceHttpsSubscriber
 * @group login_logout
 */
class ForceHttpsSubscriberTest extends UnitTestCase {

  protected $subscriber;
  protected $serverBackup;

  protected function setUp(): void {
    parent::setUp();
    $this->subscriber = new ForceHttpsSubscriber();
    $this->serverBackup = $_SERVER;
  }

  protected function tearDown(): void {
    $_SERVER = $this->serverBackup;
    parent::tearDown();
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testSubscribedEvents() {
    $events = ForceHttpsSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals('onKernelRequest', $events[KernelEvents::REQUEST][0]);
    $this->assertEquals(255, $events[KernelEvents::REQUEST][1]);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testOnKernelRequestNotMain() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(FALSE);

    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    unset($_SERVER['HTTPS']);

    $this->subscriber->onKernelRequest($event);

    $this->assertArrayNotHasKey('HTTPS', $_SERVER);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testOnKernelRequestHttps() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);

    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    unset($_SERVER['HTTPS']);

    $this->subscriber->onKernelRequest($event);

    $this->assertEquals('on', $_SERVER['HTTPS']);
    // Note: session_status() and ini_set() are global side effects hard to check 
    // but the logic path should be covered.
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testOnKernelRequestNotHttps() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);

    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
    unset($_SERVER['HTTPS']);

    $this->subscriber->onKernelRequest($event);

    $this->assertArrayNotHasKey('HTTPS', $_SERVER);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testOnKernelRequestMissingHeader() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);

    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    unset($_SERVER['HTTPS']);

    $this->subscriber->onKernelRequest($event);

    $this->assertArrayNotHasKey('HTTPS', $_SERVER);
  }
}
