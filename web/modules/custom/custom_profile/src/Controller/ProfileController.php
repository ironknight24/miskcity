<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for the My Account dashboard page.
 *
 * Assembles the two profile sub-forms (picture upload and personal data) and
 * returns them as a single themed render array so the custom_profile Twig
 * template can lay them out side-by-side.
 */
class ProfileController extends ControllerBase {

  /**
   * Renders the My Account page.
   *
   * Instantiates ProfilePictureForm and ProfileForm via the form builder and
   * hands both render arrays to the custom_profile theme hook together with
   * the module's front-end asset library.
   *
   * @return array
   *   A render array using the custom_profile theme hook.
   */
  public function myAccount() {
    $form1 = \Drupal::formBuilder()->getForm('Drupal\custom_profile\Form\ProfilePictureForm');
    $form2 = \Drupal::formBuilder()->getForm('Drupal\custom_profile\Form\ProfileForm');
    return [
      '#theme' => 'custom_profile',
      '#profile_picture_form' => $form1,
      '#form' => $form2,
      '#attached' => [
        'library' => [
          'custom_profile/profile_assets',
        ],
      ],
    ];
  }

}
