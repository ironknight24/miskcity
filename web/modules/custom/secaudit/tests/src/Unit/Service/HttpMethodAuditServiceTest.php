<?php

namespace Drupal\Tests\secaudit\Unit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\secaudit\Service\HttpMethodAuditService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\secaudit\Service\HttpMethodAuditService
 * @group secaudit
 */
class HttpMethodAuditServiceTest extends UnitTestCase
{
  /**
   * @covers ::__construct
   * @covers ::detectUnexpectedHttpMethod
   * @covers ::detectUnsupportedHttpMethods
   * @covers ::logIfMethodDisallowed
   */
  public function testHttpMethodAuditPaths(): void
  {
    $requestStack = new RequestStack();
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory->method('get')->with('secaudit')->willReturn($logger);
    $service = new HttpMethodAuditService($requestStack, $loggerFactory);

    $logger->expects($this->once())->method('warning');
    $request = Request::create('/path', 'PUT');
    $request->headers->set('x-real-ip', '127.0.0.1');
    $requestStack->push($request);
    $service->detectUnexpectedHttpMethod();

    $safeRequestStack = new RequestStack();
    $safeLoggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $safeLogger = $this->createMock(LoggerChannelInterface::class);
    $safeLoggerFactory->method('get')->with('secaudit')->willReturn($safeLogger);
    $safeLogger->expects($this->never())->method('warning');
    $safeRequestStack->push(Request::create('/path', 'GET'));
    $safeService = new HttpMethodAuditService($safeRequestStack, $safeLoggerFactory);
    $safeService->detectUnsupportedHttpMethods();
  }
}
