<?php

namespace Drupal\Tests\page_visit_counter\Unit\EventSubscriber;

use Drupal\page_visit_counter\EventSubscriber\RouteChangeSubscriber;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\page_visit_counter\EventSubscriber\RouteChangeSubscriber
 * @group page_visit_counter
 */
class RouteChangeSubscriberTest extends UnitTestCase {

  /**
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $session;

  /**
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $state;

  /**
   * @var \Drupal\page_visit_counter\EventSubscriber\RouteChangeSubscriber
   */
  protected $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->session = $this->createMock(SessionInterface::class);
    $this->state = $this->createMock(StateInterface::class);

    $this->subscriber = new RouteChangeSubscriber($this->session, $this->state);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents() {
    $events = RouteChangeSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey('kernel.response', $events);
  }

  /**
   * @covers ::onKernelResponse
   */
  public function testOnKernelResponseSuccess() {
    $request = Request::create('/home');
    $response = new Response();
    $response->setStatusCode(200);
    
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->session->method('get')->with('page_visit_counted_for')->willReturn('/previous');
    $this->state->method('get')->with('page_visit_counter.count', 0)->willReturn(10);
    
    $this->state->expects($this->once())->method('set')->with('page_visit_counter.count', 11);
    $this->session->expects($this->once())->method('set')->with('page_visit_counted_for', '/home');

    $this->subscriber->onKernelResponse($event);
  }

  /**
   * @covers ::onKernelResponse
   */
  public function testOnKernelResponseAdminPath() {
    $request = Request::create('/admin/config');
    $response = new Response();
    $response->setStatusCode(200);
    
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->state->expects($this->never())->method('set');

    $this->subscriber->onKernelResponse($event);
  }

  /**
   * @covers ::onKernelResponse
   */
  public function testOnKernelResponseAjax() {
    $request = new Request();
    $request->headers->set('X-Requested-With', 'XMLHttpRequest');
    $response = new Response();
    $response->setStatusCode(200);
    
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->state->expects($this->never())->method('set');

    $this->subscriber->onKernelResponse($event);
  }

  /**
   * @covers ::onKernelResponse
   */
  public function testOnKernelResponseAlreadyCounted() {
    $request = Request::create('/home');
    $response = new Response();
    $response->setStatusCode(200);
    
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

    $this->session->method('get')->with('page_visit_counted_for')->willReturn('/home');

    $this->state->expects($this->never())->method('set');

    $this->subscriber->onKernelResponse($event);
  }
}
