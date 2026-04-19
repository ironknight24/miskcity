<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Controller\ControllerBase;

class FamilySuccessController extends ControllerBase {

  public function content() {
    return [
      '#theme' => 'success-family-member',
    ];
  }
}
