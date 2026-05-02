<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the success confirmation page after a family member is added.
 *
 * AddFamilyMemberForm redirects here when the external citizen-app API returns
 * a successful status, providing the user with positive visual feedback.
 */
class FamilySuccessController extends ControllerBase {

  /**
   * Returns the success render array for the family-member addition flow.
   *
   * @return array
   *   A render array using the success-family-member theme hook.
   */
  public function content() {
    return [
      '#theme' => 'success-family-member',
    ];
  }

}
