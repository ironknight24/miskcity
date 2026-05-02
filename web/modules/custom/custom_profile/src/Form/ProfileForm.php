<?php

namespace Drupal\custom_profile\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

class ProfileForm extends FormBase
{

  protected $globalVariablesService;
  protected $vaultConfigService;
  protected $apimanTokenService;

  public function __construct(GlobalVariablesService $globalVariablesService, VaultConfigService $vaultConfigService, ApimanTokenService $apimanTokenService)
  {
    $this->globalVariablesService = $globalVariablesService;
    $this->vaultConfigService = $vaultConfigService;
    $this->apimanTokenService = $apimanTokenService;
  }

  public static function create($container)
  {
    return new static(
      $container->get('global_module.global_variables'),
      $container->get('global_module.vault_config_service'),
      $container->get('global_module.apiman_token_service')
    );
  }

  public function getFormId()
  {
    return 'profile_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $session = \Drupal::request()->getSession();
    $user_data = $session->get('api_redirect_result') ?? [];
    // Optional: dump for debugging
    if ($form_state->get('show_success_popup')) {
      $form['#attached']['drupalSettings']['profile_form']['show_success_popup'] = TRUE;
    }
    $form['#prefix'] = '<div id="profile-form-wrapper">';
    $form['#suffix'] = '</div>';

    // First Name
    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#required' => TRUE,
      '#default_value' => $user_data['firstName'] ?? '',
      '#attributes' => [
        'placeholder' => ' ',
        'class' => ['custom-input', 'peer'],
      ],
      '#prefix' => '<div class="relative">',
      '#suffix' => '</div>'
    ];

    // Last Name
    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#required' => TRUE,
      '#default_value' => $user_data['lastName'] ?? '',
      '#attributes' => [
        'placeholder' => ' ',
        'class' => ['custom-input', 'peer'],
      ],
      '#prefix' => '<div class="relative">',
      '#suffix' => '</div>'
    ];

    // Email (disabled)
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email ID'),
      '#default_value' => $user_data['emailId'] ?? '',
      '#disabled' => TRUE,
      '#attributes' => [
        'placeholder' => ' ',
        'class' => ['custom-input', 'peer'],
      ],
      '#prefix' => '<div class="relative">',
      '#suffix' => '</div>'
    ];

    // Mobile (disabled)
    $form['mobile'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mobile Number'),
      '#default_value' => $user_data['mobileNumber'] ?? '',
      '#disabled' => TRUE,
      '#attributes' => [
        'placeholder' => ' ',
        'minlength' => 10,
        'maxlength' => 10,
        'onkeypress' => 'return event.charCode >= 48 && event.charCode <= 57',
        'class' => ['custom-input', 'peer'],
      ],
      '#prefix' => '<div class="relative">',
      '#suffix' => '</div>'
    ];

    // Gender select
    $form['gender'] = [
      '#type' => 'select',
      '#title' => $this->t('Gender'),
      '#options' => [
        '' => $this->t('Select Gender'),
        '1' => $this->t('Male'),
        '2' => $this->t('Female'),
        '3' => $this->t('Others'),
      ],
      '#required' => TRUE,
      '#default_value' => isset($user_data['genderId']) ? (string) $user_data['genderId'] : '',
      '#attributes' => [
        'class' => ['custom-input', 'peer'],
      ],
      '#prefix' => '<div class="relative">',
      '#suffix' => '</div>'
    ];

    // DOB
    $form['dob'] = [
      '#type' => 'date',
      '#title' => $this->t('Date of Birth'),
      '#required' => TRUE,
      '#default_value' => isset($user_data['dob']) ? substr($user_data['dob'], 0, 10) : '',
      '#attributes' => [
        'placeholder' => $this->t('Date of Birth'),
        'class' => ['custom-input', 'peer'],
      ],
      '#prefix' => '<div class="relative">',
      '#suffix' => '</div>'
    ];

    // Address
$form['address'] = [
  '#type' => 'textfield',
  '#title' => $this->t('Address'),
  '#required' => TRUE,
  '#required_error' => $this->t('Address is required.'),
  '#maxlength' => 50,
  '#maxlength_error' => $this->t('Address cannot be longer than 50 characters.'),
  '#minlength' => 5,
  '#minlength_error' => $this->t('Address must be at least 5 characters long.'),
  '#pattern' => '^[a-zA-Z\s]{5,}$',
  '#pattern_error' => $this->t('Address should only contain letters and spaces.'),
  '#default_value' => $user_data['address'] ?? '',
  '#attributes' => [
    'placeholder' => ' ',
    'class' => ['custom-input', 'peer'],
  ],
  '#prefix' => '<div class="relative mb-4">',
  '#suffix' => '</div>',
];




    // Submit
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
      '#attributes' => [
        'class' => ['btn', 'bg-yellow', 'hover:bg-button_hover', 'w-44', 'h-12', 'text-xl', 'update-btn','submitBtn'],
      ],
      '#ajax' => [
        'callback' => '::ajaxCallback',           // ✅ This triggers the AJAX function
        'wrapper' => 'profile-form-wrapper',      // ✅ This should match your #prefix div id
        'effect' => 'fade',                        // Optional, adds fade effect on update
      ],
    ];

    $form['#attached']['library'][] = 'custom_profile/profile_assets';
    $form['#attached']['library'][] = 'custom_profile/profile_form_popup';
    return $form;
  }

 public function validateForm(array &$form, FormStateInterface $form_state){
    $first_name = $form_state->getValue('first_name');
    $last_name = $form_state->getValue('last_name');
    $address = $form_state->getValue('address');
    $dob = $form_state->getValue('dob');
    $gender = $form_state->getValue('gender');

    // First name validation: only letters and spaces, min 2 chars
    if (!preg_match('/^[a-zA-Z\s]{2,}$/', $first_name)) {
        $form_state->setErrorByName('first_name', $this->t('First name should contain only letters and be at least 2 characters.'));
    }

    // Last name validation: only letters and spaces, min 2 chars
    if (!preg_match('/^[a-zA-Z\s]{2,}$/', $last_name)) {
        $form_state->setErrorByName('last_name', $this->t('Last name should contain only letters and be at least 2 characters.'));
    }

    // Address validation: min 5 chars
    if (strlen(trim($address)) < 5) {
        $form_state->setErrorByName('address', $this->t('Address must be at least 5 characters long.'));
    }

    // Gender validation: must select a valid option
    if (empty($gender) || !in_array($gender, ['1', '2', '3'])) {
        $form_state->setErrorByName('gender', $this->t('Please select a valid gender.'));
    }

    // DOB validation: must be a valid date in the past
    if (!empty($dob)) {
        $dob_timestamp = strtotime($dob);
        if ($dob_timestamp === FALSE || $dob_timestamp > time()) {
            $form_state->setErrorByName('dob', $this->t('Please enter a valid date of birth.'));
        }
    } else {
        $form_state->setErrorByName('dob', $this->t('Date of birth is required.'));
    }
}



  public function submitForm(array &$form, FormStateInterface $form_state)
  {

    // Get form values
    $first_name = $form_state->getValue('first_name');
    $last_name = $form_state->getValue('last_name');
    $gender = $form_state->getValue('gender');
    $dob = $form_state->getValue('dob');
    $address = $form_state->getValue('address');

    // Get session data (like email/mobile from API call)
    $session = \Drupal::request()->getSession();
    $user_data = $session->get('api_redirect_result') ?? [];

    $email = $user_data['emailId'] ?? '';
    $mobile = $user_data['mobileNumber'] ?? '';
    $user_id = $user_data['userId'] ?? '';

    // Prepare payload
    $payload = [
      'userId' => $user_id,
      'firstName' => $first_name,
      'lastName' => $last_name,
      'emailId' => $email,
      'mobileNumber' => $mobile,
      'genderId' => $gender,
      'dob' => $dob,
      'address' => $address,
      'tenantCode' => $user_data['tenantCode']
    ];

    try {
      $access_token = $this->apimanTokenService->getApimanAccessToken();
      $globalVariables = $this->vaultConfigService->getGlobalVariables();
      $client = \Drupal::httpClient();

      $response = $client->post(
        $globalVariables['apiManConfig']['config']['apiUrl'] . 'tiotcitizenapp' . $globalVariables['apiManConfig']['config']['apiVersion'] . 'user/update',
        [
          'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
          ],
          'json' => $payload,
        ]
      );

      $data = json_decode($response->getBody(), TRUE);
      if (!empty($data['status'])) {
        $session->remove('api_redirect_result');
        \Drupal::messenger()->addMessage($this->t('Profile updated successfully.'));
        $form_state->setRebuild();
        $form_state->set('show_success_popup', TRUE);
      } else {
        \Drupal::messenger()->addError($this->t('Failed to update profile.'));
      }
    } catch (\Exception $e) {
      \Drupal::logger('custom_profile_form')->error('API Error: @message', ['@message' => $e->getMessage()]);
      \Drupal::messenger()->addError($this->t('API Error. Please try again later.'));
    }
  }

  public function ajaxCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();

    // Re-render the form
    $response->addCommand(new \Drupal\Core\Ajax\ReplaceCommand('#profile-form-wrapper', $form));

    // If flag is set, trigger JS to show the popup
    if ($form_state->get('show_success_popup')) {
      $response->addCommand(new InvokeCommand('#feedback_profile', 'removeClass', ['hidden']));
    }

    return $response;
  }
}
