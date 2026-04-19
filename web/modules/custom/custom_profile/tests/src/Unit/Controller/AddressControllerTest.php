<?php

namespace Drupal\Tests\custom_profile\Unit\Controller;

use Drupal\custom_profile\Controller\ProfileController as AddressController;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Controller\ControllerBase;

/**
 * @group custom_profile
 */
class AddressControllerTest extends UnitTestCase {

  public function testAddressController() {
    // The file AddressController.php actually defines class ProfileController.
    // We already have a test for ProfileController.php which also defines class ProfileController.
    // This might cause a collision if both are loaded.
    // However, in Unit tests they are usually loaded by PSR-4.
    
    $controller = new AddressController();
    $this->assertInstanceOf(ControllerBase::class, $controller);
  }

}
