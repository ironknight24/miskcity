<?php

namespace Drupal\Tests\secaudit\Unit\EventSubscriber;

use Drupal\secaudit\EventSubscriber\RequestEventSubscriber;
use Drupal\secaudit\Service\AuditService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * @coversDefaultClass \Drupal\secaudit\EventSubscriber\RequestEventSubscriber
 * @group secaudit
 */
class RequestEventSubscriberTest extends UnitTestCase {

  protected $auditService;
  protected $subscriber;

  protected function setUp(): void {
    parent::setUp();
    $this->auditService = $this->createMock(AuditService::class);
    $this->subscriber = new RequestEventSubscriber($this->auditService);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(RequestEventSubscriber::class, $this->subscriber);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents() {
    $events = RequestEventSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals('onRequest', $events[KernelEvents::REQUEST][0]);
    $this->assertEquals(100, $events[KernelEvents::REQUEST][1]);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestNotMain() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(FALSE);

    $this->auditService->expects($this->never())->method($this->anything());

    $this->subscriber->onRequest($event);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestIgnoredPath() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);
    
    $request = $this->createMock(Request::class);
    $request->method('getPathInfo')->willReturn('/visitors/_track/something');
    $event->method('getRequest')->willReturn($request);

    $this->auditService->expects($this->never())->method($this->anything());

    $this->subscriber->onRequest($event);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestAlreadyLogged() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);
    
    $request = $this->createMock(Request::class);
    $request->method('getPathInfo')->willReturn('/some/path');
    
    $attributes = $this->createMock(ParameterBag::class);
    $attributes->method('get')->with('_secaudit_logged')->willReturn(TRUE);
    $request->attributes = $attributes;
    
    $event->method('getRequest')->willReturn($request);

    $this->auditService->expects($this->never())->method($this->anything());

    $this->subscriber->onRequest($event);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestSuccess() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);
    
    $request = new Request();
    // Path that is NOT ignored
    // Attributes bag is a real one in new Request()
    
    $event->method('getRequest')->willReturn($request);

    $this->auditService->expects($this->once())->method('detectEE1');
    $this->auditService->expects($this->once())->method('detectIE1');
    $this->auditService->expects($this->once())->method('detectEE2');
    $this->auditService->expects($this->once())->method('detectForceBrowsing');
    $this->auditService->expects($this->once())->method('detectUnexpectedHttpMethod');
    $this->auditService->expects($this->once())->method('detectUnsupportedHttpMethods');
    $this->auditService->expects($this->once())->method('detectCookieTampering');

    $this->subscriber->onRequest($event);

    $this->assertTrue($request->attributes->get('_secaudit_logged'));
  }
}
