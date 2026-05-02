<?php

namespace Drupal\custom_profile\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

/**
 * Form for viewing and updating the citizen user profile.
 *
 * Pre-populates all fields from the api_redirect_result session key written
 * during the post-login redirect flow. On submission the form calls the
 * tiotcitizenapp user/update API endpoint and, if successful, clears the
 * cached session data and triggers a success popup via AJAX.
 *
 * Fields:
 *  - first_name / last_name — editable, letters-only with minimum 2 chars.
 *  - email / mobile — read-only display values (set by the identity provider).
 *  - gender — required select (Male / Female / Others).
 *  - dob — required date, must be in the past.
 *  - address — editable, min 5 chars.
 */
class ProfileForm extends FormBase
{

  /**
   * Provides encryption helpers and global site variables.
   *
   * @var \Drupal\global_module\Service\GlobalVariablesService
   */
  protected $globalVariablesService;

  /**
   * Provides Vault-stored configuration including the API base URL.
   *
   * @var \Drupal\global_module\Service\VaultConfigService
   */
  protected $vaultConfigService;

  /**
   * Provides a short-lived bearer token for API Manager authentication.
   *
   * @var \Drupal\global_module\Service\ApimanTokenService
   */
  protected $apimanTokenService;

  /**
   * Constructs a ProfileForm.
   *
   * @param \Drupal\global_module\Service\GlobalVariablesService $globalVariablesService
   *   The global variables service.
   * @param \Drupal\global_module\Service\VaultConfigService $vaultConfigService
   *   The Vault configuration service.
   * @param \Drupal\global_module\Service\ApimanTokenService $apimanTokenService
   *   The API Manager token service.
   */
  public function __construct(GlobalVariablesService $globalVariablesService, VaultConfigService $vaultConfigService, ApimanTokenService $apimanTokenService)
  {
    $this->globalVariablesService = $globalVariablesService;
    $this->vaultConfigService = $vaultConfigService;
    $this->apimanTokenService = $apimanTokenService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container)
  {
    return new static(
      $container->get('global_module.global_variables'),
      $container->get('global_module.vault_config_service'),
      $container->get('global_module.apiman_token_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'profile_form';
  }

  /**
   * {@inheritdoc}
   *
   * Reads user data from the api_redirect_result session key and uses it as
   * the default value for each field. Email and mobile are rendered disabled
   * because they are managed by the identity provider and cannot be changed
   * here. A success-popup flag in form state triggers a drupalSettings entry
   * that the front-end JS reads after an AJAX rebuild.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $session = \Drupal::request()->getSession();
    $user_data = $session->get('api_redirect_result') ?? [];

    if ($form_state->get('show_success_popup')) {
      $form['#attached']['drupalSettings']['profile_form']['show_success_popup'] = TRUE;
    }
    $form['#prefix'] = '<div id="profile-form-wrapper">';
    $form['#suffix'] = '</div>';

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

    // Email is managed by the identity provider; display only.
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

    // Mobile is managed by the identity provider; display only.
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

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
      '#attributes' => [
        'class' => ['btn', 'bg-yellow', 'hover:bg-button_hover', 'w-44', 'h-12', 'text-xl', 'update-btn','submitBtn'],
      ],
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'profile-form-wrapper',
        'effect' => 'fade',
      ],
    ];

    $form['#attached']['library'][] = 'custom_profile/profile_assets';
    $form['#attached']['library'][] = 'custom_profile/profile_form_popup';
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Validates all editable fields server-side. Email and mobile are skipped
   * because those fields are disabled and not submitted by the browser.
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $first_name = $form_state->getValue('first_name');
    $last_name  = $form_state->getValue('last_name');
    $address    = $form_state->getValue('address');
    $dob        = $form_state->getValue('dob');
    $gender     = $form_state->getValue('gender');

    if (!preg_match('/^[a-zA-Z\s]{2,}$/', $first_name)) {
      $form_state->setErrorByName('first_name', $this->t('First name should contain only letters and be at least 2 characters.'));
    }

    if (!preg_match('/^[a-zA-Z\s]{2,}$/', $last_name)) {
      $form_state->setErrorByName('last_name', $this->t('Last name should contain only letters and be at least 2 characters.'));
    }

    if (strlen(trim($address)) < 5) {
      $form_state->setErrorByName('address', $this->t('Address must be at least 5 characters long.'));
    }

    if (empty($gender) || !in_array($gender, ['1', '2', '3'])) {
      $form_state->setErrorByName('gender', $this->t('Please select a valid gender.'));
    }

    if (!empty($dob)) {
      $dob_timestamp = strtotime($dob);
      if ($dob_timestamp === FALSE || $dob_timestamp > time()) {
        $form_state->setErrorByName('dob', $this->t('Please enter a valid date of birth.'));
      }
    } else {
      $form_state->setErrorByName('dob', $this->t('Date of birth is required.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Assembles the update payload from form values and the session data (which
   * holds immutable fields such as email and mobile), POSTs to the citizen-app
   * user/update endpoint, and on success clears the cached session data and
   * flags the form state so the AJAX callback can trigger the success popup.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $first_name = $form_state->getValue('first_name');
    $last_name  = $form_state->getValue('last_name');
    $gender     = $form_state->getValue('gender');
    $dob        = $form_state->getValue('dob');
    $address    = $form_state->getValue('address');

    $session   = \Drupal::request()->getSession();
    $user_data = $session->get('api_redirect_result') ?? [];

    $email   = $user_data['emailId'] ?? '';
    $mobile  = $user_data['mobileNumber'] ?? '';
    $user_id = $user_data['userId'] ?? '';

    $payload = [
      'userId'       => $user_id,
      'firstName'    => $first_name,
      'lastName'     => $last_name,
      'emailId'      => $email,
      'mobileNumber' => $mobile,
      'genderId'     => $gender,
      'dob'          => $dob,
      'address'      => $address,
      'tenantCode'   => $user_data['tenantCode']
    ];

    try {
      $access_token    = $this->apimanTokenService->getApimanAccessToken();
      $globalVariables = $this->vaultConfigService->getGlobalVariables();
      $client          = \Drupal::httpClient();

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

  /**
   * AJAX callback that re-renders the form and, on success, reveals the popup.
   *
   * Replaces the profile-form-wrapper element with the rebuilt form, then
   * removes the "hidden" CSS class from the success feedback element when the
   * show_success_popup flag is set in form state.
   *
   * @param array $form
   *   The rebuilt form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response containing replace and (optionally) invoke commands.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();

    $response->addCommand(new \Drupal\Core\Ajax\ReplaceCommand('#profile-form-wrapper', $form));

    if ($form_state->get('show_success_popup')) {
      $response->addCommand(new InvokeCommand('#feedback_profile', 'removeClass', ['hidden']));
    }

    return $response;
  }

}
