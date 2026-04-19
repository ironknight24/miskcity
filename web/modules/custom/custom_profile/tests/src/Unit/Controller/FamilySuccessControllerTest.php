<?php

namespace Drupal\Tests\custom_profile\Unit\Controller;

use Drupal\custom_profile\Controller\FamilySuccessController;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\custom_profile\Controller\FamilySuccessController
 * @group custom_profile
 */
class FamilySuccessControllerTest extends UnitTestCase {

  /**
   * @covers ::content
   */
  public function testContent() {
    $controller = new FamilySuccessController();
    $result = $controller->content();
    $this->assertEquals('success-family-member', $result['#theme']);
  }

}
