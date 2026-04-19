<?php

namespace Drupal\reportgrievance\Controller;

use Drupal\Core\Controller\ControllerBase;

class GrievanceFailureController extends ControllerBase {

  public function content() {
    return [
      '#theme' => 'grievance_failure',
    ];
  }
}
