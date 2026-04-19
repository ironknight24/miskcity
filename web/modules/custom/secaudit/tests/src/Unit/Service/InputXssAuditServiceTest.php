<?php

namespace Drupal\Tests\secaudit\Unit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\secaudit\Service\InputXssAuditService;
use Drupal\secaudit\Service\InputXssScanService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @group secaudit
 */
class InputXssAuditServiceTest extends UnitTestCase
{
  public function testDetectIe1SkipsIgnoredAndSafeRequests(): void
  {
    $requestStack = new RequestStack();
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory->method('get')->with('secaudit')->willReturn($logger);
    $service = new InputXssAuditService($requestStack, $loggerFactory, new InputXssScanService());

    $this->assertSame([], $service->detectIE1());

    $ignored = Request::create('/admin/config');
    $requestStack->push($ignored);
    $logger->expects($this->never())->method('warning');
    $this->assertSame([], $service->detectIE1());

    $requestStack = new RequestStack();
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory->method('get')->with('secaudit')->willReturn($logger);
    $service = new InputXssAuditService($requestStack, $loggerFactory, new InputXssScanService());
    $safe = Request::create('/safe', 'GET', ['value' => 'plain-text']);
    $requestStack->push($safe);
    $logger->expects($this->never())->method('warning');
    $this->assertSame([], $service->detectIE1());
  }

  public function testDetectIe1FindsJsonPayloads(): void
  {
    $requestStack = new RequestStack();
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory->method('get')->with('secaudit')->willReturn($logger);
    $service = new InputXssAuditService($requestStack, $loggerFactory, new InputXssScanService());

    $request = Request::create(
      '/safe',
      'POST',
      [],
      ['tracking' => 'ok'],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['payload' => '<script>alert(1)</script>'])
    );
    $request->headers->set('x-real-ip', '127.0.0.1');
    $requestStack->push($request);
    $logger->expects($this->once())->method('warning');

    $findings = $service->detectIE1();

    $this->assertNotEmpty($findings);
    $this->assertSame('json_body', $findings[0]['type']);
  }
}
