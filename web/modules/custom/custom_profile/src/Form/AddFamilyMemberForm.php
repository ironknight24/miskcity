<?php

namespace Drupal\custom_profile\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\global_module\Service\FileUploadService;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

/**
 * Form for adding a new family member to the citizen user account.
 *
 * The form is broken into discrete protected helper methods, each building
 * a logical section:
 *  - buildFormContainer() — wrapper markup and hidden user ID field.
 *  - addIdentityFields() — name and date-of-birth inputs.
 *  - addRelationshipFields() — gender and relationship selects.
 *  - addContactFields() — mobile number and email inputs.
 *  - addUploadAndTermsFields() — profile picture upload and T&C checkbox.
 *  - addActionFields() — submit and cancel buttons.
 *
 * On submission, if a file is present it is uploaded via FileUploadService
 * first, and the returned URL is included in the payload sent to the
 * family-members/add-family-member API endpoint. Success or failure redirects
 * to the dedicated result pages.
 */
class AddFamilyMemberForm extends FormBase
{

  /**
   * The HTTP client used to POST the new family-member payload to the API.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Service that handles multipart file upload to the document store.
   *
   * @var \Drupal\global_module\Service\FileUploadService
   */
  protected $fileUploadService;

  /**
   * The current HTTP request, used to access uploaded files.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Provides encryption helpers and global site variables.
   *
   * @var \Drupal\global_module\Service\GlobalVariablesService
   */
  protected $globalVariableService;

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
   * Constructs an AddFamilyMemberForm.
   *
   * @param \Drupal\global_module\Service\FileUploadService $fileUploadService
   *   The file upload service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack; the current request is resolved immediately.
   * @param \Drupal\global_module\Service\GlobalVariablesService $globalVariableService
   *   The global variables service.
   * @param \Drupal\global_module\Service\VaultConfigService $vaultConfigService
   *   The Vault configuration service.
   * @param \Drupal\global_module\Service\ApimanTokenService $apimanTokenService
   *   The API Manager token service.
   */
  public function __construct(FileUploadService $fileUploadService, ClientInterface $http_client, RequestStack $request_stack, GlobalVariablesService $globalVariableService, VaultConfigService $vaultConfigService, ApimanTokenService $apimanTokenService)
  {
    $this->httpClient = $http_client;
    $this->fileUploadService = $fileUploadService;
    $this->request = $request_stack->getCurrentRequest();
    $this->globalVariableService = $globalVariableService;
    $this->vaultConfigService = $vaultConfigService;
    $this->apimanTokenService = $apimanTokenService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('global_module.file_upload_service'),
      $container->get('http_client'),
      $container->get('request_stack'),
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
    return 'add_family_member_form';
  }

  /**
   * {@inheritdoc}
   *
   * Delegates field construction to focused helper methods, then calls
   * finalizeForm() to attach the theme hook and asset libraries.
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form = $this->buildFormContainer($form);
    $form = $this->addIdentityFields($form, $form_state);
    $form = $this->addRelationshipFields($form);
    $form = $this->addContactFields($form);
    $form = $this->addUploadAndTermsFields($form);
    $form = $this->addActionFields($form);
    return $this->finalizeForm($form);
  }

  /**
   * {@inheritdoc}
   *
   * Handles optional file upload before building the API payload. If the
   * file upload service returns an error the form bails early with a messenger
   * error and skips the API call. On API success the user is redirected to the
   * family success page; on failure or exception, to the family failure page.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $values    = $form_state->getValues();
    $session   = \Drupal::request()->getSession();
    $user_data = $session->get('api_redirect_result') ?? [];

    $image_url     = NULL;
    $response_data = [];

    if (
      isset($_FILES['files']['full_path']['upload_file']) &&
      is_uploaded_file($_FILES['files']['tmp_name']['upload_file'])
    ) {
      $upload_response = $this->fileUploadService->uploadFile($this->request);
      if ($upload_response instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
        $response_data = json_decode($upload_response->getContent(), TRUE);
        if (!empty($response_data['fileName'])) {
          $image_url = $response_data['fileName'];
        } elseif (!empty($response_data['error'])) {
          $this->messenger()->addError($this->t('File upload error: @error', [
            '@error' => $response_data['error'],
          ]));
          return;
        }
      }
    }

    $access_token    = $this->apimanTokenService->getApimanAccessToken();
    $globalVariables = $this->vaultConfigService->getGlobalVariables();

    $payload = [
      'name'         => $values['first_name'],
      'dateOfBirth'  => $values['calendar'],
      'gender'       => $values['gender'],
      'relationship' => $values['relations'],
      'phone'        => $values['phone_number'],
      'emailId'      => $values['email'],
      'userId'       => $user_data['userId'] ?? '',
      'imageUrl'     => $image_url,
    ];

    \Drupal::logger('custom_profile')->info('Submitting family member: <pre>@payload</pre>', [
      '@payload' => print_r($payload, TRUE),
    ]);

    $endpoint = $globalVariables['apiManConfig']['config']['apiUrl'] .
      'tiotcitizenapp' .
      $globalVariables['apiManConfig']['config']['apiVersion'] .
      'family-members/add-family-member';

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'json'    => $payload,
        'headers' => [
          'Content-Type'  => 'application/json',
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]);
      $data = json_decode($response->getBody()->getContents(), TRUE);
      \Drupal::logger('custom_profile')->info('API response: <pre>@response</pre>', [
        '@response' => print_r($data, TRUE),
      ]);

      if ($data['status'] && $data['status'] === TRUE) {
        \Drupal::logger('custom_profile')->info('Redirecting to success route.');
        $form_state->setRedirect('custom_profile.family_success');
      } else {
        \Drupal::logger('custom_profile')->info('Redirecting to failure route.');
        $form_state->setRedirect('custom_profile.family_failure');
      }
    } catch (\Exception $e) {
      \Drupal::logger('custom_profile')->error('API error: @message', ['@message' => $e->getMessage()]);
      $form_state->setRedirect('custom_profile.family_failure');
    }
  }

  /**
   * Adds the outer wrapper markup and the hidden user ID field to the form.
   *
   * @param array $form
   *   The form render array to modify.
   *
   * @return array
   *   The modified form render array.
   */
  protected function buildFormContainer(array $form): array
  {
    $form['#prefix'] = '<div id="add-family-member-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attributes']['class'][] = 'form-sec p-4 lg:px-10 lg:py-12 bg-white text-center lg:text-start s:mb-24 xs:mb-20';
    $form['user_id'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'user_id'],
    ];

    return $form;
  }

  /**
   * Adds the full name and date-of-birth fields to the form.
   *
   * The calendar field enforces a maximum of today so future dates cannot be
   * selected. An inline date-error helper renders any existing form-state
   * errors immediately below the field.
   *
   * @param array $form
   *   The form render array to modify.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state, used for inline error display.
   *
   * @return array
   *   The modified form render array.
   */
  protected function addIdentityFields(array $form, FormStateInterface $form_state): array
  {
    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => [
          'peer', 'w-full', 'lg:max-w-lg', 'px-2.5', 'pb-2.5', 'pt-4', 'text-sm',
          'text-medium_dark', 'bg-transparent', 'rounded-lg', 'border', 'border-gray-300',
          'appearance-none', 'text-base', 's:text-sm', 'xs:text-sm',
        ],
        'placeholder' => ' ',
        'autocomplete' => 'off',
        'id' => 'first_name',
      ],
      '#prefix' => '<div class="errors-first_name"><div class="relative">',
      '#suffix' => '</div></div>',
    ];

    $form['calendar'] = [
      '#type' => 'date',
      '#title' => $this->t('Date of Birth'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => [
          'peer', 'w-full', 'lg:max-w-lg', 'px-2.5', 'pb-2.5', 'pt-4', 'text-sm',
          'text-medium_dark', 'bg-transparent', 'rounded-lg', 'border', 'border-gray-300',
          'text-base', 's:text-sm', 'xs:text-sm',
        ],
        'placeholder' => 'DD-MM-YYYY',
        'id' => 'calendar',
        'max' => date('Y-m-d'),
      ],
      '#prefix' => '<div class="errors-calendar"><div class="relative">',
      '#suffix' => $this->getDateErrorMarkup($form_state, 'calendar') . '</div></div>',
    ];

    return $form;
  }

  /**
   * Adds the gender and relationship select fields to the form.
   *
   * @param array $form
   *   The form render array to modify.
   *
   * @return array
   *   The modified form render array.
   */
  protected function addRelationshipFields(array $form): array
  {
    $form['gender'] = [
      '#type' => 'select',
      '#title' => $this->t('Gender'),
      '#required' => TRUE,
      '#options' => [
        'Male'   => $this->t('Male'),
        'Female' => $this->t('Female'),
        'Others' => $this->t('Others'),
      ],
      '#attributes' => [
        'class' => [
          'peer', 'w-full', 'lg:max-w-lg', 'text-base', 's:text-sm', 'xs:text-sm',
          'font-medium', 'select', 'rounded-lg', 'border', 'border-gray-300',
          'px-2.5', 'pb-2.5', 'pt-4',
        ],
        'id' => 'gender',
      ],
      '#prefix' => '<div class="errors-gender"><div class="relative">',
      '#suffix' => '</div></div>',
    ];

    $form['relations'] = [
      '#type' => 'select',
      '#title' => $this->t('Relationship'),
      '#required' => TRUE,
      '#options' => [
        ''        => $this->t('Relationship*'),
        'Mother'  => $this->t('Mother'),
        'Father'  => $this->t('Father'),
        'Sister'  => $this->t('Sister'),
        'Brother' => $this->t('Brother'),
        'Wife'    => $this->t('Wife'),
        'Husband' => $this->t('Husband'),
        'Daughter' => $this->t('Daughter'),
        'Son'     => $this->t('Son'),
      ],
      '#attributes' => [
        'class' => [
          'peer', 'w-full', 'lg:max-w-lg', 'text-base', 's:text-sm', 'xs:text-sm',
          'font-medium', 'select', 'rounded-lg', 'border', 'border-gray-300',
          'px-2.5', 'pb-2.5', 'pt-4',
        ],
        'id' => 'relations',
      ],
      '#prefix' => '<div class="errors-relations"><div class="relative">',
      '#suffix' => '</div></div>',
    ];

    return $form;
  }

  /**
   * Adds the mobile number and email address fields to the form.
   *
   * @param array $form
   *   The form render array to modify.
   *
   * @return array
   *   The modified form render array.
   */
  protected function addContactFields(array $form): array
  {
    $form['phone_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mobile Number'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => [
          'peer', 'w-full', 'lg:max-w-lg', 'text-base', 's:text-sm', 'xs:text-sm',
          'rounded-lg', 'border', 'border-gray-300', 'px-2.5', 'pb-2.5', 'pt-4',
        ],
        'maxlength' => 10,
        'minlength' => 10,
        'id' => 'phone_number',
        'placeholder' => ' ',
      ],
      '#prefix' => '<div class="errors-phone_number"><div class="relative">',
      '#suffix' => '</div></div>',
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email ID'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => [
          'peer', 'w-full', 'lg:max-w-lg', 'text-base', 's:text-sm', 'xs:text-sm',
          'rounded-lg', 'border', 'border-gray-300', 'px-2.5', 'pb-2.5', 'pt-4',
        ],
        'placeholder' => ' ',
        'id' => 'email',
      ],
      '#prefix' => '<div class="errors-email"><div class="relative">',
      '#suffix' => '</div></div>',
    ];

    return $form;
  }

  /**
   * Adds the profile picture upload field and the Terms & Conditions checkbox.
   *
   * The upload field uses server-side validators to restrict the accepted MIME
   * types to JPG/JPEG/PNG and to enforce a 2 MB maximum file size. The
   * terms checkbox is required; unchecked submission is blocked both
   * client-side (JS) and server-side (Drupal required validation).
   *
   * @param array $form
   *   The form render array to modify.
   *
   * @return array
   *   The modified form render array.
   */
  protected function addUploadAndTermsFields(array $form): array
  {
    $form['upload_file'] = [
      '#type' => 'file',
      '#title' => $this->t('<span class="font-nevis">Upload Picture</span>'),
      '#description' => $this->t('<span class="text-xs">(Supported file types: JPG, JPEG & PNG, 2MB max)</span>'),
      '#required' => TRUE,
      '#required_error' => $this->t('Please upload a file'),
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg', 'jpeg', 'png'],
        'file_validate_size' => [2 * 1024 * 1024],
      ],
      '#attributes' => [
        'class' => [
          'peer', 'w-1/2', 'lg:max-w-lg', 'px-2.5', 'pb-2.5', 'pt-4', 'text-sm',
          'text-medium_dark', 'bg-transparent', 'rounded-lg', 'text-base', 's:text-sm',
          'xs:text-sm', 'rounded-lg', 'border', 'border-gray-300 ',
        ]
      ],
      '#prefix' => '<div class="file-upload-wrapper file-upload-container no-float-label">',
      '#suffix' => '<div class="upload-file-error"></div></div>',
    ];

    $form['terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('<span>I agree to the <a href="" class="link link-primary text-blue-600 underline hover:text-blue-900" target="_blank">Terms and Conditions</a></span>'),
      '#required' => TRUE,
      '#required_error' => $this->t('You must agree to the Terms and Conditions'),
      '#attributes' => [
        'class' => ['checkbox', 'w-6', 'h-6', 'rounded', 'cursor-pointer', 'border', 'border-gray-400'],
        'id' => 'terms',
      ],
      '#prefix' => '<div class="terms-container flex items-center space-x-2 no-float-label relative">',
      '#suffix' => '</div><div class="error-message-wrapper"></div>',
    ];

    return $form;
  }

  /**
   * Adds the submit and cancel action buttons to the form.
   *
   * The cancel button uses an inline onclick to reload the page rather than
   * a Drupal link, keeping it fully client-side with no server round-trip.
   *
   * @param array $form
   *   The form render array to modify.
   *
   * @return array
   *   The modified form render array.
   */
  protected function addActionFields(array $form): array
  {
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#attributes' => [
        'class' => [
          'btn', 'btn-warning', 'lg:h-14', 'lg:w-44', 'xs:h-10', 'text-white',
          'capitalize', 'text-lg', 'font-semibold', 'cursor-pointer', 'rounded-[10px]',
          'bg-[#ffcc00]', 'px-[2px]', 'pt-[2px]', 'pb-[4px]', 'submitBtn', 'engage-btn',
        ],
      ],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#attributes' => [
        'onclick' => 'window.location.reload()',
        'class' => [
          'btn', 'bg-transparent', 'text-black/75', 'px-14', 'text-[1.125rem]',
          "font-['Open_Sans']", 'rounded-[10px]', 'transition-colors', 'duration-200',
          'ease-in-out', 'border', 'border-black/25', 'cursor-pointer', 'inline',
          'font-bold', 'btn-outline', 'lg:h-14', 'lg:w-44', 'xs:h-10', 'capitalize',
          'text-medium_dark', 'button', 'rounded-lg'
        ],
      ],
    ];

    return $form;
  }

  /**
   * Applies the custom theme hook and attaches front-end asset libraries.
   *
   * The ajax_loader library from global_module provides the loading spinner
   * displayed while the form submits.
   *
   * @param array $form
   *   The assembled form render array.
   *
   * @return array
   *   The finalised form render array with theme and libraries set.
   */
  protected function finalizeForm(array $form): array
  {
    $form['#theme'] = 'add-family-member';
    $form['#attached']['library'][] = 'custom_profile/add-family-member-library';
    $form['#attached']['library'][] = 'global_module/ajax_loader';
    return $form;
  }

  /**
   * Generates inline HTML for a field-level date validation error.
   *
   * Used only by the calendar field suffix to surface errors directly below
   * the date input instead of at the top of the form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state containing any existing errors.
   * @param string $field_name
   *   The form field key to check for errors.
   *
   * @return string
   *   An HTML error paragraph, or an empty string when no error exists.
   */
  private function getDateErrorMarkup(FormStateInterface $form_state, $field_name)
  {
    $errors = $form_state->getErrors();
    if (isset($errors[$field_name])) {
      return '<p class="text-red-600 text-sm mt-1">' . $errors[$field_name] . '</p>';
    }
    return '';
  }

  /**
   * AJAX callback invoked on submit when JavaScript is enabled.
   *
   * Returns the full form render array whether or not there are errors, so
   * Drupal can display inline validation messages without a page reload.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The form render array.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state)
  {
    if ($form_state->hasAnyErrors()) {
      return $form;
    }
    return $form;
  }

}
