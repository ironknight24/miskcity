<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Controller\ControllerBase;

class ProfileController extends ControllerBase {
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
