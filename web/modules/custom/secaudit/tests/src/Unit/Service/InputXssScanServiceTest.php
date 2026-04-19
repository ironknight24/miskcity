<?php

namespace Drupal\Tests\secaudit\Unit\Service;

use Drupal\secaudit\Service\InputXssScanService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group secaudit
 */
class InputXssScanServiceTest extends UnitTestCase
{
  public function testShouldScanRequest(): void
  {
    $service = new InputXssScanService();

    $this->assertFalse($service->shouldScanRequest(NULL));
    $this->assertFalse($service->shouldScanRequest(Request::create('/admin/config')));
    $this->assertTrue($service->shouldScanRequest(Request::create('/safe')));
  }

  public function testCollectInputsAndScanInputs(): void
  {
    $service = new InputXssScanService();
    $request = Request::create(
      '/safe',
      'POST',
      ['queryValue' => 'ok'],
      ['cookie' => 'safe'],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['payload' => '<script>alert(1)</script>'])
    );

    $inputs = $service->collectInputs($request);
    $findings = $service->scanInputs($inputs);

    $this->assertArrayHasKey('json_body', $inputs);
    $this->assertNotEmpty($findings);
    $this->assertSame('json_body', $findings[0]['type']);
  }

  public function testScanInputsIgnoresLongAndScalarUnsafeValuesOnly(): void
  {
    $service = new InputXssScanService();
    $findings = $service->scanInputs([
      'request' => [
        str_repeat('a', 5000),
        ['nested' => 'javascript:alert(1)'],
        'plain-text',
      ],
    ]);

    $this->assertCount(1, $findings);
    $this->assertSame('request', $findings[0]['type']);
  }
}
