<?php

namespace Drupal\custom_profile\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

/**
 * Form for displaying and uploading/removing the user profile picture.
 *
 * This form is embedded in the My Account page alongside ProfileForm. It
 * renders the current profile picture (sourced from the session data) and
 * provides:
 *  - A file input (<input type="file">) wired to a JS fileUpload() handler
 *    that previews and uploads the selected image client-side.
 *  - A "Remove" button (type="button", not submit) that triggers a modal
 *    confirmation, after which the ajaxCallback sends a null profilePic value
 *    to the user/update API and resets the displayed image.
 *
 * The standard submitForm() is a no-op because all mutations are performed
 * through AJAX callbacks triggered by JavaScript, not standard form POSTs.
 */
class ProfilePictureForm extends FormBase
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
   * Constructs a ProfilePictureForm.
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
    return 'profile_picture_form';
  }

  /**
   * {@inheritdoc}
   *
   * Reads the profile picture URL from the api_redirect_result session key.
   * Falls back to the theme default image when the stored value is empty or
   * the literal string "null" (returned by the API when no picture is set).
   * All picture mutations are client-side; this method only renders state.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $session   = \Drupal::request()->getSession();
    $user_data = $session->get('api_redirect_result') ?? [];

    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['#method'] = 'post';
    $form['#action'] = '';

    $form['profile_picture_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'profile-form-wrapper'],
    ];

    $form['profile_picture_wrapper']['profile_picture'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['avatar', 'flex', 'flex-col', 'items-center', 'relative'],
      ],
    ];

    // Use the stored profile picture URL or fall back to the default avatar.
    $profile_pic = (!empty($user_data['profilePic']) && $user_data['profilePic'] !== "null")
      ? htmlspecialchars($user_data['profilePic'], ENT_QUOTES, 'UTF-8')
      : '/themes/custom/engage_theme/images/Profile/profile_pic.png';

    $form['image'] = [
      '#type' => 'markup',
      '#markup' => '
        <div class="w-28 rounded-full mb-3 aspect-square block overflow-hidden">
          <img src="' . $profile_pic . '" class="h-full w-full object-cover profilePicSrc" alt="Profile Image">
        </div>',
    ];

    $form['edit_label'] = [
      '#type' => 'html_tag',
      '#tag' => 'label',
      '#value' => $this->t('Edit Profile Picture'),
      '#attributes' => [
        'for' => 'profilePic',
        'class' => ['text-sm', 'font-bold', 'font-[Open_Sans]', 'border-2', 'px-4', 'cursor-pointer', 'translateLabel'],
        'label-alias' => 'la_edit_profile_picture',
      ],
    ];

    // The onchange attribute wires the file input to the JS fileUpload helper,
    // which previews the image and stores the URL for the hidden field.
    $form['upload_file'] = [
      '#type' => 'file',
      '#attributes' => [
        'onchange' => "fileUpload(this, 'profilePic')",
        'class' => ['form-control', 'profilePic', 'invisible', 'hidden'],
        'id' => 'profilePic',
        'accept' => 'image/*',
      ],
      '#name' => 'upload_file',
    ];

    $form['profilePic_filename'] = [
      '#type' => 'hidden',
      '#attributes' => ['class' => ['form-control', 'profilePic_name'], 'id' => 'profilePic_name'],
      '#name' => 'profilePic_filename',
    ];

    // type="button" prevents this element from triggering a standard form
    // submission; the modal confirmation is handled entirely in JavaScript.
    $form['remove'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Remove'),
      '#attributes' => [
        'type' => 'button',
        'id' => 'remove-profile-picture',
        'class' => [
          'removeImg',
          'text-sm',
          'font-bold',
          'font-[Open_Sans]',
          'border-2',
          'px-4',
          'py-0.5',
          'cursor-pointer',
          'translateLabel',
        ],
        'data-modal-target' => 'remove-profile-picture-modal',
        'data-modal-toggle' => 'remove-profile-picture-modal',
        'engage-button' => 'engage-button-modal',
      ],
    ];

    $form['note'] = [
      '#type' => 'markup',
      '#markup' => '<p class="flex justify-center text-[#a0a0a0] supportP text-sm font-[Open_Sans] font-bold my-4">' .
        $this->t('Supported file types: JPEG, PNG & file size limit is 2MB') .
        '</p>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * No-op: this form does not handle standard POST submissions. All picture
   * changes are triggered by AJAX callbacks or client-side JavaScript.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // No-op: form uses AJAX-only actions.
  }

  /**
   * AJAX callback that removes the profile picture via the user/update API.
   *
   * Reads user identity fields from the session, sends a user/update POST with
   * profilePic set to the string "null", and on success resets the image
   * markup to the default avatar and clears the hidden filename field.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The profile_picture_wrapper sub-array for partial AJAX replacement.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state)
  {
    \Drupal::logger('custom_profile_picture_form')->debug('AJAX Remove callback triggered.');

    $session   = \Drupal::request()->getSession();
    $user_data = $session->get('api_redirect_result') ?? [];
    $first_name = $user_data['firstName'];
    $last_name  = $user_data['lastName'];
    $email      = $user_data['emailId'] ?? '';
    $mobile     = $user_data['mobileNumber'] ?? '';
    $user_id    = $user_data['userId'] ?? '';
    $payload = [
      'firstName'    => $first_name,
      'lastName'     => $last_name,
      'emailId'      => $email,
      'mobileNumber' => $mobile,
      'tenantCode'   => $user_data['tenantCode'],
      'profilePic'   => 'null',
      'userId'       => $user_id
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
        \Drupal::logger('custom_profile')->notice('Profile removed successfully.');
      } else {
        \Drupal::logger('custom_profile')->notice('Failed to remove profile');
      }
    } catch (\Exception $e) {
      \Drupal::logger('custom_profile_form')->error('API Error: @message', ['@message' => $e->getMessage()]);
      \Drupal::logger('custom_profile_form')->error($this->t('API Error. Please try again later.'));
    }

    // Reset the displayed avatar to the default image after successful removal.
    $form['image']['#markup'] = '
      <div class="w-28 rounded-full mb-3 aspect-square block overflow-hidden">
        <img src="/themes/custom/engage_theme/images/Profile/profile_pic.png" class="h-full w-full object-cover profilePicSrc" alt="Default Image">
      </div>';

    $form['profilePic_filename']['#value'] = '';

    \Drupal::logger('custom_profile_picture')->notice('Profile picture removed.');

    return $form['profile_picture_wrapper'];
  }

}
