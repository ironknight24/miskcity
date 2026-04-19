<?php

namespace Drupal\Tests\login_logout\Unit\EventSubscriber;

use Drupal\login_logout\EventSubscriber\SessionDebugSubscriber;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @coversDefaultClass \Drupal\login_logout\EventSubscriber\SessionDebugSubscriber
 * @group login_logout
 */
class SessionDebugSubscriberTest extends UnitTestCase {

  protected $logger;
  protected $subscriber;

  protected function setUp(): void {
    parent::setUp();
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->subscriber = new SessionDebugSubscriber($this->logger);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(SessionDebugSubscriber::class, $this->subscriber);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents() {
    $events = SessionDebugSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals('onKernelRequest', $events[KernelEvents::REQUEST][0]);
    $this->assertEquals(200, $events[KernelEvents::REQUEST][1]);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testOnKernelRequestMain() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);

    // Currently the method has no logic besides the check.
    $this->subscriber->onKernelRequest($event);
    $this->assertTrue(TRUE);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testOnKernelRequestSub() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(FALSE);

    $this->subscriber->onKernelRequest($event);
    $this->assertTrue(TRUE);
  }
}
