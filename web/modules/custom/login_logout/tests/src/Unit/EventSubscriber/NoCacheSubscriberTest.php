<?php

namespace Drupal\Tests\login_logout\Unit\EventSubscriber;

use Drupal\login_logout\EventSubscriber\NoCacheSubscriber;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\login_logout\EventSubscriber\NoCacheSubscriber
 * @group login_logout
 */
class NoCacheSubscriberTest extends UnitTestCase {

  protected $currentUser;
  protected $subscriber;

  protected function setUp(): void {
    parent::setUp();
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->subscriber = new NoCacheSubscriber($this->currentUser);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents() {
    $events = NoCacheSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    $this->assertEquals('addNoCacheHeaders', $events[KernelEvents::RESPONSE][0]);
  }

  /**
   * @covers ::__construct
   * @covers ::addNoCacheHeaders
   */
  public function testAddNoCacheHeadersAuthenticated() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);

    $kernel = $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class);
    $request = new \Symfony\Component\HttpFoundation\Request();
    $response = new Response();
    $event = new ResponseEvent($kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->addNoCacheHeaders($event);

    $this->assertEquals('must-revalidate, no-cache, no-store, private', $response->headers->get('Cache-Control'));
    $this->assertEquals('no-cache', $response->headers->get('Pragma'));
    $this->assertEquals('0', $response->headers->get('Expires'));
  }

  /**
   * @covers ::addNoCacheHeaders
   */
  public function testAddNoCacheHeadersAnonymous() {
    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);

    $kernel = $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class);
    $request = new \Symfony\Component\HttpFoundation\Request();
    $response = new Response();
    $event = new ResponseEvent($kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, $response);

    $this->subscriber->addNoCacheHeaders($event);

    // Headers should not be set by this subscriber
    $this->assertNotEquals('no-cache', $response->headers->get('Pragma'));
  }
}
