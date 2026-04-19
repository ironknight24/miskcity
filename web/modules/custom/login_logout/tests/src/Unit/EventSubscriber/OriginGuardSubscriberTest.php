<?php

namespace Drupal\Tests\login_logout\Unit\EventSubscriber;

use Drupal\login_logout\EventSubscriber\OriginGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\login_logout\EventSubscriber\OriginGuardSubscriber
 * @group login_logout
 */
class OriginGuardSubscriberTest extends UnitTestCase {

  protected $subscriber;
  protected $allowedOrigins = ['https://example.com', 'https://trusted.site'];

  protected function setUp(): void {
    parent::setUp();
    $this->subscriber = new OriginGuardSubscriber([
      'allowedOrigins' => $this->allowedOrigins
    ]);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(OriginGuardSubscriber::class, $this->subscriber);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = new ContainerBuilder();
    $container->setParameter('cors.config', ['allowedOrigins' => $this->allowedOrigins]);
    $subscriber = OriginGuardSubscriber::create($container);
    $this->assertInstanceOf(OriginGuardSubscriber::class, $subscriber);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents() {
    $events = OriginGuardSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals('checkOrigin', $events[KernelEvents::REQUEST][0]);
    $this->assertEquals(300, $events[KernelEvents::REQUEST][1]);
  }

  /**
   * @covers ::checkOrigin
   */
  public function testCheckOriginNoHeader() {
    $kernel = $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class);
    $request = new Request();
    $event = new RequestEvent($kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->checkOrigin($event);
    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::checkOrigin
   */
  public function testCheckOriginAllowed() {
    $kernel = $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class);
    $request = new Request();
    $request->headers->set('Origin', 'https://example.com/');
    $event = new RequestEvent($kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->checkOrigin($event);
    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::checkOrigin
   */
  public function testCheckOriginDisallowed() {
    $kernel = $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class);
    $request = new Request();
    $request->headers->set('Origin', 'https://malicious.com');
    $event = new RequestEvent($kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);

    $this->subscriber->checkOrigin($event);
    $response = $event->getResponse();
    
    $this->assertNotNull($response);
    $this->assertEquals(403, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Invalid CORS', $data['error']);
    $this->assertEquals('https://malicious.com', $data['origin']);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructorNormalization() {
    $subscriber = new OriginGuardSubscriber([
      'allowedOrigins' => ['https://slashed.com/']
    ]);
    
    $kernel = $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class);
    $request = new Request();
    $request->headers->set('Origin', 'https://slashed.com');
    $event = new RequestEvent($kernel, $request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST);

    $subscriber->checkOrigin($event);
    $this->assertNull($event->getResponse());
  }
}
