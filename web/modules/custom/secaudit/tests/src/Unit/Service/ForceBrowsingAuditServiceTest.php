<?php

namespace Drupal\Tests\secaudit\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\secaudit\Service\ForceBrowsingAuditService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @coversDefaultClass \Drupal\secaudit\Service\ForceBrowsingAuditService
 * @group secaudit
 */
class ForceBrowsingAuditServiceTest extends UnitTestCase
{
  protected RequestStack $requestStack;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected LoggerChannelInterface $logger;
  protected AccountProxyInterface $currentUser;

  protected function setUp(): void
  {
    parent::setUp();
    $this->requestStack = new RequestStack();
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);

    $this->loggerFactory->method('get')->with('secaudit')->willReturn($this->logger);

    $container = new ContainerBuilder();
    $container->set('current_user', $this->currentUser);
    \Drupal::setContainer($container);
  }

  /**
   * @covers ::detectForceBrowsing
   */
  public function testNoRequestDoesNothing(): void
  {
    $this->logger->expects($this->never())->method('warning');
    $service = new ForceBrowsingAuditService($this->requestStack, $this->loggerFactory);
    $service->detectForceBrowsing();
  }

  /**
   * @covers ::__construct
   * @covers ::detectForceBrowsing
   */
  public function testRestrictedAnonymousRequestLogsWarning(): void
  {
    $request = Request::create('/admin/config');
    $request->headers->set('x-real-ip', '10.0.0.1');
    $session = $this->createMock(SessionInterface::class);
    $session->method('has')->with('uid')->willReturn(FALSE);
    $request->setSession($session);
    $this->requestStack->push($request);

    $this->currentUser->method('hasPermission')->with('access administration pages')->willReturn(FALSE);
    $this->currentUser->method('id')->willReturn(0);
    $this->logger->expects($this->once())->method('warning');

    $service = new ForceBrowsingAuditService($this->requestStack, $this->loggerFactory);
    $service->detectForceBrowsing();
  }

  /**
   * @covers ::detectForceBrowsing
   */
  public function testAuthorizedRestrictedRequestDoesNotLog(): void
  {
    $request = Request::create('/admin/config');
    $session = $this->createMock(SessionInterface::class);
    $session->method('has')->with('uid')->willReturn(TRUE);
    $request->setSession($session);
    $this->requestStack->push($request);

    $this->currentUser->method('hasPermission')->with('access administration pages')->willReturn(TRUE);
    $this->logger->expects($this->never())->method('warning');

    $service = new ForceBrowsingAuditService($this->requestStack, $this->loggerFactory);
    $service->detectForceBrowsing();
  }
}
