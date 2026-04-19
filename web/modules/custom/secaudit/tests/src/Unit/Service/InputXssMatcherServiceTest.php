<?php

namespace Drupal\Tests\secaudit\Unit\Service;

use Drupal\secaudit\Service\InputXssMatcherService;
use Drupal\Tests\UnitTestCase;

/**
 * @group secaudit
 */
class InputXssMatcherServiceTest extends UnitTestCase
{
  public function testFindMatchingPattern(): void
  {
    $service = new InputXssMatcherService();

    $this->assertNotNull($service->findMatchingPattern('<script>alert(1)</script>'));
    $this->assertNotNull($service->findMatchingPattern('javascript:alert(1)'));
    $this->assertNull($service->findMatchingPattern('plain-text'));
  }
}
