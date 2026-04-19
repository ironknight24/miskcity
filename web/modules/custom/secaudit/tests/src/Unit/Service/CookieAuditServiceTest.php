<?php

namespace Drupal\Tests\secaudit\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\secaudit\Service\CookieAuditService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @coversDefaultClass \Drupal\secaudit\Service\CookieAuditService
 * @group secaudit
 */
class CookieAuditServiceTest extends UnitTestCase
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
    $this->currentUser->method('id')->willReturn(42);
    $this->loggerFactory->method('get')->with('secaudit')->willReturn($this->logger);

    $container = new ContainerBuilder();
    $container->set('current_user', $this->currentUser);
    \Drupal::setContainer($container);
  }

  /**
   * @covers ::detectCookieTampering
   */
  public function testBaselineSnapshotIsCreated(): void
  {
    $request = Request::create('/profile', 'GET', [], ['cookie_a' => 'one']);
    $request->headers->set('x-real-ip', '1.1.1.1');
    $request->headers->set('User-Agent', 'UA-1');
    $session = $this->createMock(SessionInterface::class);
    $session->method('getId')->willReturn('sid-1');
    $session->method('get')->with('secaudit.session_snapshot')->willReturn(NULL);
    $session->expects($this->once())->method('set')->with(
      'secaudit.session_snapshot',
      $this->isType('array')
    );
    $request->setSession($session);
    $this->requestStack->push($request);
    $this->logger->expects($this->never())->method('warning');

    $service = new CookieAuditService($this->requestStack, $this->loggerFactory);
    $service->detectCookieTampering();
  }

  /**
   * @covers ::__construct
   * @covers ::detectCookieTampering
   * @covers ::logAddedCookies
   * @covers ::logDeletedCookies
   * @covers ::logModifiedCookies
   * @covers ::logSessionIdChanges
   * @covers ::logIpChanges
   * @covers ::logUserAgentChanges
   */
  public function testTamperingSignalsAreLogged(): void
  {
    $request = Request::create('/profile', 'GET', [], [
      'cookie_a' => 'new-value',
      'cookie_b' => 'stable',
      'cookie_new' => 'added',
    ]);
    $request->headers->set('x-real-ip', '2.2.2.2');
    $request->headers->set('User-Agent', 'UA-2');

    $session = $this->createMock(SessionInterface::class);
    $session->method('getId')->willReturn('sid-2');
    $session->method('get')->with('secaudit.session_snapshot')->willReturn([
      'cookies' => [
        'cookie_a' => 'old-value',
        'cookie_b' => 'stable',
        'cookie_removed' => 'gone',
      ],
      'ip' => '1.1.1.1',
      'ua' => 'UA-1',
      'session_id' => 'sid-1',
    ]);
    $session->expects($this->once())->method('set')->with(
      'secaudit.session_snapshot',
      $this->isType('array')
    );
    $request->setSession($session);
    $this->requestStack->push($request);

    $this->logger->expects($this->exactly(6))->method('warning');

    $service = new CookieAuditService($this->requestStack, $this->loggerFactory);
    $service->detectCookieTampering();
  }
}
