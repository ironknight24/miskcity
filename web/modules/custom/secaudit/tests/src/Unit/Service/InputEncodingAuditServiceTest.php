<?php

namespace Drupal\Tests\secaudit\Unit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\secaudit\Service\EncodingDetectorService;
use Drupal\secaudit\Service\InputEncodingAuditService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\secaudit\Service\InputEncodingAuditService
 * @group secaudit
 */
class InputEncodingAuditServiceTest extends UnitTestCase
{
  protected $requestStack;
  protected $loggerFactory;
  protected $detector;
  protected $service;

  protected function setUp(): void
  {
    parent::setUp();

    $this->requestStack = $this->createMock(RequestStack::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->detector = $this->createMock(EncodingDetectorService::class);

    $this->service = new InputEncodingAuditService(
      $this->requestStack,
      $this->loggerFactory,
      $this->detector
    );
  }

  /**
   * @covers ::detectEE1
   */
  public function testDetectEE1(): void
  {
    $request = new Request();
    $request->query->set('q', 'v');
    $request->headers->set('x-real-ip', '127.0.0.1');

    $this->requestStack->method('getCurrentRequest')->willReturnOnConsecutiveCalls(NULL, $request);

    // First call (NULL)
    $this->service->detectEE1();

    // Second call (request)
    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->with('secaudit')->willReturn($logger);
    $this->detector->method('hasDoubleEncoding')->willReturn(TRUE);
    $logger->expects($this->once())->method('warning');

    $this->service->detectEE1();
    $this->assertTrue($request->attributes->get('_secaudit_ee1_detected'));
  }

  /**
   * @covers ::detectEE2
   */
  public function testDetectEE2(): void
  {
    $request = new Request();
    $request->request->set('p', 'v');
    $request->headers->set('x-real-ip', '127.0.0.1');
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->with('secaudit')->willReturn($logger);

    $this->detector->method('detectUnexpectedEncodingReason')->willReturn('mixed_encoding_styles');
    $logger->expects($this->once())->method('warning');

    $this->service->detectEE2();
    $this->assertTrue($request->attributes->get('_secaudit_ee2_detected'));

    // Test early return if already detected
    $this->service->detectEE2();
  }

  /**
   * @covers ::getEE1Reason
   * @covers ::getScalarInputs
   * @covers ::logAnomaly
   */
  public function testHelpers(): void
  {
    $request = new Request();
    $request->cookies->set('c', 'val');
    $request->headers->set('x-real-ip', '1.1.1.1');
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    
    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->with('secaudit')->willReturn($logger);

    $service = new class($this->requestStack, $this->loggerFactory, $this->detector) extends InputEncodingAuditService {
      public function callGetEE1Reason(string $v): ?string { return $this->getEE1Reason($v); }
      public function callGetScalarInputs($r) { return iterator_to_array($this->getScalarInputs($r)); }
      public function callLogAnomaly($req, $c, $m, $v, $re) { $this->logAnomaly($req, $c, $m, $v, $re); }
    };

    $this->detector->method('hasDoubleEncoding')->willReturn(FALSE);
    $this->detector->method('hasHtmlDoubleEncoding')->willReturn(TRUE);
    $this->assertSame('double_html_encoding', $service->callGetEE1Reason('v'));

    $inputs = $service->callGetScalarInputs($request);
    $this->assertContains('val', $inputs);

    $logger->expects($this->once())->method('warning')->with(
      $this->stringContains('EE1: msg'),
      $this->callback(fn($args) => $args['@ip'] === '1.1.1.1')
    );
    $service->callLogAnomaly($request, 'EE1', 'msg', 'val', 'reason');
  }
}
