<?php

namespace Drupal\Tests\secaudit\Unit\Service;

use Drupal\secaudit\Service\AuditService;
use Drupal\secaudit\Service\CookieAuditService;
use Drupal\secaudit\Service\ForceBrowsingAuditService;
use Drupal\secaudit\Service\HttpMethodAuditService;
use Drupal\secaudit\Service\InputEncodingAuditService;
use Drupal\secaudit\Service\InputXssAuditService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\secaudit\Service\AuditService
 * @group secaudit
 */
class AuditServiceTest extends UnitTestCase
{
  /**
   * @covers ::__construct
   * @covers ::detectForceBrowsing
   * @covers ::detectUnexpectedHttpMethod
   * @covers ::detectUnsupportedHttpMethods
   * @covers ::detectCookieTampering
   * @covers ::detectIE1
   * @covers ::detectEE1
   * @covers ::detectEE2
   */
  public function testFacadeDelegatesToSpecializedServices(): void
  {
    $forceBrowsing = $this->createMock(ForceBrowsingAuditService::class);
    $httpMethod = $this->createMock(HttpMethodAuditService::class);
    $cookieAudit = $this->createMock(CookieAuditService::class);
    $inputEncoding = $this->createMock(InputEncodingAuditService::class);
    $inputXss = $this->createMock(InputXssAuditService::class);

    $forceBrowsing->expects($this->once())->method('detectForceBrowsing');
    $httpMethod->expects($this->once())->method('detectUnexpectedHttpMethod');
    $httpMethod->expects($this->once())->method('detectUnsupportedHttpMethods');
    $cookieAudit->expects($this->once())->method('detectCookieTampering');
    $inputEncoding->expects($this->once())->method('detectEE1');
    $inputEncoding->expects($this->once())->method('detectEE2');
    $inputXss->expects($this->once())->method('detectIE1')->willReturn([['type' => 'json_body']]);

    $service = new AuditService(
      $forceBrowsing,
      $httpMethod,
      $cookieAudit,
      $inputEncoding,
      $inputXss
    );

    $service->detectForceBrowsing();
    $service->detectUnexpectedHttpMethod();
    $service->detectUnsupportedHttpMethods();
    $service->detectCookieTampering();
    $this->assertSame([['type' => 'json_body']], $service->detectIE1());
    $service->detectEE1();
    $service->detectEE2();
  }
}
