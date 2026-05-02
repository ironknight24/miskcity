<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the failure page when a family member could not be added.
 *
 * AddFamilyMemberForm redirects here on any API error or non-successful
 * response status, giving the user clear feedback that the submission failed.
 */
class FamilyFailureController extends ControllerBase {

  /**
   * Returns the failure render array for the family-member addition flow.
   *
   * @return array
   *   A render array using the failed-family-member theme hook.
   */
  public function content() {
    return [
      '#theme' => 'failed-family-member',
    ];
  }

}
