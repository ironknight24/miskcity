<?php

namespace Drupal\Tests\login_logout\Unit\EventSubscriber;

use Drupal\login_logout\EventSubscriber\UserRedirectSubscriber;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Url;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\login_logout\EventSubscriber\UserRedirectSubscriber
 * @group login_logout
 */
class UserRedirectSubscriberTest extends UnitTestCase {

  protected $currentUser;
  protected $currentPath;
  protected $urlGenerator;
  protected $urlAssembler;
  protected $subscriber;

  protected function setUp(): void {
    parent::setUp();

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->currentPath = $this->createMock(CurrentPathStack::class);
    $this->urlGenerator = $this->createMock(\Drupal\Core\Routing\UrlGeneratorInterface::class);
    $path_validator = $this->createMock(\Drupal\Core\Path\PathValidatorInterface::class);
    $this->urlAssembler = $this->createMock(\Drupal\Core\Utility\UnroutedUrlAssemblerInterface::class);

    $container = new ContainerBuilder();
    $container->set('url_generator', $this->urlGenerator);
    $container->set('path.validator', $path_validator);
    $container->set('unrouted_url_assembler', $this->urlAssembler);
    \Drupal::setContainer($container);

    $this->subscriber = new UserRedirectSubscriber($this->currentUser, $this->currentPath);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(UserRedirectSubscriber::class, $this->subscriber);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents() {
    $events = UserRedirectSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals('onRequest', $events[KernelEvents::REQUEST][0]);
    $this->assertEquals(30, $events[KernelEvents::REQUEST][1]);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestAuthenticated() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);
    $this->currentUser->method('isAnonymous')->willReturn(FALSE);

    $this->currentPath->expects($this->never())->method('getPath');
    $this->subscriber->onRequest($event);
  }

  /**
   * @covers ::onRequest
   */
  public function testOnRequestNotMain() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(FALSE);

    $this->subscriber->onRequest($event);
    $this->assertNull($event->getResponse());
  }

  /**
   * @covers ::onRequest
   * @covers ::isProtectedPath
   */
  public function testOnRequestProtectedPath() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);
    $this->currentUser->method('isAnonymous')->willReturn(TRUE);
    $this->currentPath->method('getPath')->willReturn('/my-account');

    $this->urlAssembler->method('assemble')
      ->willReturn('/user-login?destination=my-account');

    $event->expects($this->once())->method('setResponse')->with($this->isInstanceOf(RedirectResponse::class));

    $this->subscriber->onRequest($event);
  }

  /**
   * @covers ::onRequest
   * @covers ::isProtectedPath
   */
  public function testOnRequestNotProtectedPath() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);
    $this->currentUser->method('isAnonymous')->willReturn(TRUE);
    $this->currentPath->method('getPath')->willReturn('/public-page');

    $event->expects($this->never())->method('setResponse');

    $this->subscriber->onRequest($event);
  }

  /**
   * @covers ::isProtectedPath
   */
  public function testIsProtectedPath() {
    $reflection = new \ReflectionClass(UserRedirectSubscriber::class);
    $method = $reflection->getMethod('isProtectedPath');
    $method->setAccessible(TRUE);

    $this->assertTrue($method->invoke($this->subscriber, '/report-grievances'));
    $this->assertTrue($method->invoke($this->subscriber, '/my-account'));
    $this->assertTrue($method->invoke($this->subscriber, '/manage-address'));
    $this->assertTrue($method->invoke($this->subscriber, '/add-address'));
    $this->assertTrue($method->invoke($this->subscriber, '/service-request'));
    $this->assertTrue($method->invoke($this->subscriber, '/request/123'));
    $this->assertTrue($method->invoke($this->subscriber, '/active-sessions'));
    $this->assertTrue($method->invoke($this->subscriber, '/enquiry'));
    
    $this->assertFalse($method->invoke($this->subscriber, '/public'));
    $this->assertFalse($method->invoke($this->subscriber, NULL));
  }
}
