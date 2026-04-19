<?php

namespace Drupal\Tests\reportgrievance\Unit\Controller;

use Drupal\reportgrievance\Controller\GrievanceFailureController;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\reportgrievance\Controller\GrievanceFailureController
 * @group reportgrievance
 */
class GrievanceFailureControllerTest extends UnitTestCase {

  /**
   * @covers ::content
   */
  public function testContent() {
    $controller = new GrievanceFailureController();
    $result = $controller->content();
    $this->assertEquals('grievance_failure', $result['#theme']);
  }

}
