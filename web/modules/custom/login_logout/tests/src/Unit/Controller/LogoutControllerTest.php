<?php

namespace Drupal\Tests\login_logout\Unit\Controller;

use Drupal\login_logout\Controller\LogoutController;
use Drupal\login_logout\Service\OAuthLoginService;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * @coversDefaultClass \Drupal\login_logout\Controller\LogoutController
 * @group login_logout
 */
class LogoutControllerTest extends UnitTestCase {

  protected $currentUser;
  protected $sessionManager;
  protected $requestStack;
  protected $oauthLoginService;
  protected $messenger;
  protected $session;
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->sessionManager = $this->createMock(SessionManagerInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->oauthLoginService = $this->createMock(OAuthLoginService::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->session = $this->createMock(SessionInterface::class);

    $container = new ContainerBuilder();
    $container->set('current_user', $this->currentUser);
    $container->set('session_manager', $this->sessionManager);
    $container->set('request_stack', $this->requestStack);
    $container->set('login_logout.oauth_login_service', $this->oauthLoginService);
    $container->set('messenger', $this->messenger);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->controller = new LogoutController(
      $this->currentUser,
      $this->sessionManager,
      $this->requestStack,
      $this->oauthLoginService
    );
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(LogoutController::class, $this->controller);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = \Drupal::getContainer();
    $controller = LogoutController::create($container);
    $this->assertInstanceOf(LogoutController::class, $controller);
  }

  /**
   * @covers ::logout
   */
  public function testLogoutNotAuthenticated() {
    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);
    $response = $this->controller->logout();
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals('/', $response->getTargetUrl());
  }

  /**
   * @covers ::logout
   */
  public function testLogoutNoIdToken() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    
    $request = $this->createMock(Request::class);
    $request->method('getSession')->willReturn($this->session);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    
    $this->session->method('get')->with('login_logout.id_token')->willReturn(NULL);
    $this->messenger->expects($this->once())->method('addError');

    $response = $this->controller->logout();
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals('/', $response->getTargetUrl());
  }

  /**
   * @covers ::logout
   */
  public function testLogoutSuccess() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    
    $request = $this->createMock(Request::class);
    $request->method('getSession')->willReturn($this->session);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    
    $this->session->method('get')->with('login_logout.id_token')->willReturn('token123');
    $this->oauthLoginService->method('logout')->with('token123')->willReturn(['status' => 200]);
    
    $this->session->expects($this->exactly(3))->method('remove');
    $this->sessionManager->expects($this->once())->method('destroy');
    $this->messenger->expects($this->once())->method('addStatus');

    $response = $this->controller->logout();
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals('/', $response->getTargetUrl());
  }

  /**
   * @covers ::logout
   */
  public function testLogoutStatusNot200() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    
    $request = $this->createMock(Request::class);
    $request->method('getSession')->willReturn($this->session);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    
    $this->session->method('get')->with('login_logout.id_token')->willReturn('token123');
    $this->oauthLoginService->method('logout')->with('token123')->willReturn(['status' => 400]);
    
    $this->session->expects($this->never())->method('remove');
    $this->sessionManager->expects($this->never())->method('destroy');
    $this->messenger->expects($this->once())->method('addStatus');

    $response = $this->controller->logout();
    $this->assertInstanceOf(RedirectResponse::class, $response);
  }

  /**
   * @covers ::logout
   */
  public function testLogoutException() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    
    $request = $this->createMock(Request::class);
    $request->method('getSession')->willReturn($this->session);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    
    $this->session->method('get')->with('login_logout.id_token')->willReturn('token123');
    $this->oauthLoginService->method('logout')->willThrowException(new \Exception('Logout failed'));
    
    $this->messenger->expects($this->once())->method('addError');

    $response = $this->controller->logout();
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertEquals('/', $response->getTargetUrl());
  }
}
