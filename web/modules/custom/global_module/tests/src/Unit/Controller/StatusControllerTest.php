<?php

namespace Drupal\Tests\global_module\Unit\Controller;

use Drupal\global_module\Controller\StatusController;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\global_module\Controller\StatusController
 * @group global_module
 */
class StatusControllerTest extends UnitTestCase {

  /**
   * @covers ::statusPage
   */
  public function testStatusPage() {
    $request = new Request([
      'status' => 1,
      'message' => 'Test Message',
      'formData' => 'test-form',
    ]);

    $controller = new StatusController();
    $result = $controller->statusPage($request);

    $this->assertEquals('status', $result['#theme']);
    $this->assertEquals(1, $result['#status']);
    $this->assertEquals('Test Message', $result['#message']);
    $this->assertEquals('test-form', $result['#form_data']);
  }

}
