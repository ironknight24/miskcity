<?php

namespace Drupal\Tests\global_module\Unit\EventSubscriber;

use Drupal\global_module\EventSubscriber\ApiRedirectSubscriber;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManagerInterface;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;

/**
 * @coversDefaultClass \Drupal\global_module\EventSubscriber\ApiRedirectSubscriber
 * @group global_module
 */
class ApiRedirectSubscriberTest extends UnitTestCase {

  protected $globalVariablesService;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $httpClient;
  protected $currentUser;
  protected $pathStack;
  protected $aliasManager;
  protected $logger;
  protected $urlGenerator;
  protected $subscriber;

  protected function setUp(): void {
    parent::setUp();

    $this->globalVariablesService = $this->createMock(GlobalVariablesService::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);
    $this->httpClient = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['post'])->getMock();
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->pathStack = $this->createMock(CurrentPathStack::class);
    $this->aliasManager = $this->createMock(AliasManagerInterface::class);
    $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

    $container = new ContainerBuilder();
    $container->set('current_user', $this->currentUser);
    $container->set('path.current', $this->pathStack);
    $container->set('path_alias.manager', $this->aliasManager);
    $container->set('global_module.global_variables', $this->globalVariablesService);
    $container->set('global_module.vault_config_service', $this->vaultConfigService);
    $container->set('global_module.apiman_token_service', $this->apimanTokenService);
    $container->set('http_client', $this->httpClient);
    $container->set('url_generator', $this->urlGenerator);

    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);
    $container->set('logger.factory', $logger_factory);

    \Drupal::setContainer($container);

    $this->subscriber = new ApiRedirectSubscriber();
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testGetSubscribedEvents() {
    $events = ApiRedirectSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey('kernel.request', $events);
  }

  /**
   * Helper to create a request event.
   */
  protected function createEvent(Request $request) {
    $kernel = $this->createMock(HttpKernelInterface::class);
    return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
  }

  /**
   * @covers ::onKernelRequest
   * @covers ::shouldProcess
   */
  public function testOnKernelRequestUnauthenticated() {
    $this->currentUser->method('isAuthenticated')->willReturn(FALSE);
    $request = Request::create('/home');
    $event = $this->createEvent($request);

    $this->httpClient->expects($this->never())->method('post');
    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::onKernelRequest
   * @covers ::shouldProcess
   */
  public function testOnKernelRequestAjax() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $request = Request::create('/home');
    $request->headers->set('X-Requested-With', 'XMLHttpRequest');
    $event = $this->createEvent($request);

    $this->httpClient->expects($this->never())->method('post');
    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::onKernelRequest
   * @covers ::shouldProcess
   */
  public function testOnKernelRequestNonHtml() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $request = Request::create('/home');
    $request->setRequestFormat('json');
    $event = $this->createEvent($request);

    $this->httpClient->expects($this->never())->method('post');
    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::onKernelRequest
   * @covers ::isAdminPath
   */
  public function testOnKernelRequestAdminPath() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $request = Request::create('/admin/config');
    $event = $this->createEvent($request);

    $this->pathStack->method('getPath')->willReturn('/admin/config');
    $this->aliasManager->method('getAliasByPath')->willReturn('/admin/config');

    $this->httpClient->expects($this->never())->method('post');
    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::onKernelRequest
   */
  public function testOnKernelRequestSessionExists() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $request = Request::create('/home');
    $request->setRequestFormat('html');
    $session = $this->createMock(SessionInterface::class);
    $session->method('has')->with('api_redirect_result')->willReturn(TRUE);
    $request->setSession($session);
    $event = $this->createEvent($request);

    $this->pathStack->method('getPath')->willReturn('/home');
    $this->aliasManager->method('getAliasByPath')->willReturn('/home');

    $this->httpClient->expects($this->never())->method('post');
    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::onKernelRequest
   * @covers ::callYourApi
   * @covers ::processApiResult
   * @covers ::logError
   */
  public function testOnKernelRequestApiError() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $request = Request::create('/home');
    $request->setRequestFormat('html');
    $session = $this->createMock(SessionInterface::class);
    $session->method('has')->willReturn(FALSE);
    $request->setSession($session);
    $event = $this->createEvent($request);

    $this->pathStack->method('getPath')->willReturn('/home');
    $this->aliasManager->method('getAliasByPath')->willReturn('/home');

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.com/', 'apiVersion' => '/v1/']]
    ]);

    $this->httpClient->method('post')->willThrowException(new \Exception('API Error'));
    $this->logger->expects($this->once())->method('error')->with($this->stringContains('API call failed'));

    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::processApiResult
   */
  public function testProcessApiResultInvalidFormat() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $request = Request::create('/home');
    $request->setRequestFormat('html');
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);
    $event = $this->createEvent($request);

    $this->pathStack->method('getPath')->willReturn('/home');
    $this->aliasManager->method('getAliasByPath')->willReturn('/home');

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.com/', 'apiVersion' => '/v1/']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    // Return a string instead of array for 'data' key
    $body->method('__toString')->willReturn(json_encode(['data' => 'not_an_array']));
    $response->method('getBody')->willReturn($body);
    $this->httpClient->method('post')->willReturn($response);

    $this->logger->expects($this->once())->method('error')->with('Invalid API response format.');

    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::processApiResult
   */
  public function testProcessApiResultMissingUserId() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $request = Request::create('/home');
    $request->setRequestFormat('html');
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);
    $event = $this->createEvent($request);

    $this->pathStack->method('getPath')->willReturn('/home');
    $this->aliasManager->method('getAliasByPath')->willReturn('/home');

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.com/', 'apiVersion' => '/v1/']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn(json_encode(['data' => ['something' => 'else']]));
    $response->method('getBody')->willReturn($body);
    $this->httpClient->method('post')->willReturn($response);

    $session->expects($this->once())->method('remove')->with('api_redirect_result');
    $this->logger->expects($this->once())->method('warning')->with('API response missing userId.');

    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::onKernelRequest
   * @covers ::decryptSensitiveFields
   * @covers ::processApiResult
   * @covers ::logInfo
   */
  public function testOnKernelRequestFullSuccess() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $this->currentUser->method('getEmail')->willReturn('user@test.com');
    $request = Request::create('/home');
    $request->setRequestFormat('html');
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);
    $event = $this->createEvent($request);

    $this->pathStack->method('getPath')->willReturn('/home');
    $this->aliasManager->method('getAliasByPath')->willReturn('/home');

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.com/', 'apiVersion' => '/v1/']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('__toString')->willReturn(json_encode([
      'data' => [
        'userId' => 123,
        'emailId' => 'enc_email',
        'mobileNumber' => 'enc_mobile'
      ]
    ]));
    $response->method('getBody')->willReturn($body);
    $this->httpClient->method('post')->willReturn($response);

    $this->globalVariablesService->method('decrypt')->willReturnMap([
      ['enc_email', 'dec_email'],
      ['enc_mobile', 'dec_mobile'],
    ]);

    $session->expects($this->once())->method('set')->with('api_redirect_result', [
      'userId' => 123,
      'emailId' => 'dec_email',
      'mobileNumber' => 'dec_mobile'
    ]);
    
    $this->logger->expects($this->once())->method('info');

    $this->subscriber->onKernelRequest($event);
  }

  /**
   * @covers ::onKernelRequest
   * @covers ::shouldRedirect
   * @covers ::redirectToFront
   */
  public function testOnKernelRequestRedirect() {
    $this->currentUser->method('isAuthenticated')->willReturn(TRUE);
    $request = Request::create('/home');
    $request->setRequestFormat('html');
    $session = $this->createMock(SessionInterface::class);
    $request->setSession($session);
    $event = $this->createEvent($request);

    $this->pathStack->method('getPath')->willReturn('/home');
    $this->aliasManager->method('getAliasByPath')->willReturn('/home');

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'http://api.com/', 'apiVersion' => '/v1/']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    // Returning 'redirect_me' as data string
    $body->method('__toString')->willReturn(json_encode(['data' => 'redirect_me']));
    $response->method('getBody')->willReturn($body);
    $this->httpClient->method('post')->willReturn($response);

    $this->urlGenerator->method('generateFromRoute')->with('<front>')->willReturn('/front');

    $this->subscriber->onKernelRequest($event);
    
    $this->assertInstanceOf(\Symfony\Component\HttpFoundation\RedirectResponse::class, $event->getResponse());
    $this->assertEquals('/front', $event->getResponse()->getTargetUrl());
  }
}
