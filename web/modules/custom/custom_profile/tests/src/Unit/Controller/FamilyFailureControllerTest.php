<?php

namespace Drupal\Tests\custom_profile\Unit\Controller;

use Drupal\custom_profile\Controller\FamilyFailureController;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\custom_profile\Controller\FamilyFailureController
 * @group custom_profile
 */
class FamilyFailureControllerTest extends UnitTestCase {

  /**
   * @covers ::content
   */
  public function testContent() {
    $controller = new FamilyFailureController();
    $result = $controller->content();
    $this->assertEquals('failed-family-member', $result['#theme']);
  }

}
