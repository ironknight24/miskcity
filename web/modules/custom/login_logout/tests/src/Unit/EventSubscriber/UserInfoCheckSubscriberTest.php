<?php

namespace Drupal\Tests\login_logout\Unit\EventSubscriber;

use Drupal\login_logout\EventSubscriber\UserInfoCheckSubscriber;
use Drupal\login_logout\Service\UserInfoValidator;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\login_logout\EventSubscriber\UserInfoCheckSubscriber
 * @group login_logout
 */
class UserInfoCheckSubscriberTest extends UnitTestCase {

  protected $currentUser;
  protected $sessionManager;
  protected $validator;
  protected $logger;
  protected $currentPath;
  protected $subscriber;

  protected function setUp(): void {
    parent::setUp();

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->sessionManager = $this->createMock(SessionManagerInterface::class);
    $this->validator = $this->createMock(UserInfoValidator::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->currentPath = $this->createMock(CurrentPathStack::class);

    $this->subscriber = new UserInfoCheckSubscriber(
      $this->currentUser,
      $this->sessionManager,
      $this->validator,
      $this->logger,
      $this->currentPath
    );
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(UserInfoCheckSubscriber::class, $this->subscriber);
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents() {
    $events = UserInfoCheckSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
    $this->assertEquals('checkUserInfo', $events[KernelEvents::REQUEST][0]);
    $this->assertEquals(30, $events[KernelEvents::REQUEST][1]);
  }

  /**
   * @covers ::checkUserInfo
   */
  public function testCheckUserInfoNotMainRequest() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(FALSE);

    $this->currentPath->expects($this->never())->method('getPath');
    $this->subscriber->checkUserInfo($event);
  }

  /**
   * @covers ::checkUserInfo
   */
  public function testCheckUserInfoSkipPath() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);

    $this->currentPath->method('getPath')->willReturn('/user-login');
    $this->currentUser->expects($this->never())->method('isAnonymous');

    $this->subscriber->checkUserInfo($event);
  }

  /**
   * @covers ::checkUserInfo
   */
  public function testCheckUserInfoAnonymous() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);
    $this->currentPath->method('getPath')->willReturn('/node/1');
    
    $this->currentUser->method('isAnonymous')->willReturn(TRUE);
    $this->validator->expects($this->never())->method('validate');

    $this->subscriber->checkUserInfo($event);
  }

  /**
   * @covers ::checkUserInfo
   */
  public function testCheckUserInfoAdmin() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);
    $this->currentPath->method('getPath')->willReturn('/node/1');
    
    $this->currentUser->method('isAnonymous')->willReturn(FALSE);
    $this->currentUser->method('hasPermission')->with('administer site configuration')->willReturn(TRUE);
    $this->validator->expects($this->never())->method('validate');

    $this->subscriber->checkUserInfo($event);
  }

  /**
   * @covers ::checkUserInfo
   */
  public function testCheckUserInfoValid() {
    $event = $this->createMock(RequestEvent::class);
    $event->method('isMainRequest')->willReturn(TRUE);
    $this->currentPath->method('getPath')->willReturn('/node/1');
    
    $this->currentUser->method('isAnonymous')->willReturn(FALSE);
    $this->currentUser->method('hasPermission')->willReturn(FALSE);
    
    $this->validator->method('validate')->willReturn(['uid' => 1]);
    $this->sessionManager->expects($this->never())->method('delete');

    $this->subscriber->checkUserInfo($event);
  }

  /**
   * @covers ::checkUserInfo
   * @covers ::forceLogout
   */
  public function testCheckUserInfoInvalidLogout() {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $request = new Request();
    $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

    $this->currentPath->method('getPath')->willReturn('/node/1');
    
    $this->currentUser->method('isAnonymous')->willReturn(FALSE);
    $this->currentUser->method('hasPermission')->willReturn(FALSE);
    $this->currentUser->method('id')->willReturn(123);
    
    $this->validator->method('validate')->willReturn([]);
    
    $this->sessionManager->expects($this->once())->method('delete')->with(123);
    $this->logger->expects($this->once())->method('notice');

    $this->subscriber->checkUserInfo($event);

    $response = $event->getResponse();
    $this->assertInstanceOf(\Drupal\Core\Routing\TrustedRedirectResponse::class, $response);
    $this->assertEquals('/logout', $response->getTargetUrl());
  }
}
