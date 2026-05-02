<?php

namespace Drupal\reportgrievance\Form;

use Drupal\charts\Plugin\chart\Type\Type;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\reportgrievance\Service\GrievanceApiService;
use Drupal\global_module\Service\FileUploadService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * ReportGrievanceForm class.
 *
 * Provides a form for citizens to report grievances with category selection,
 * location data, file uploads, and submission to external API.
 * Handles dynamic loading of grievance types and subtypes via AJAX.
 */
class ReportGrievanceForm extends FormBase
{

  public const TYPE_KEY = '#type';
  public const REQUIRED = '#required';
  public const ATTRIBUTES_KEY = '#attributes';
  public const REQUIRED_ERROR = '#required_error';
  public const FOCUS_YELLOW_BORDER = 'focus:border-yellow-500';
  public const YELLOW_RING_FOCUS = 'focus:ring-yellow-500';

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * @var \Drupal\reportgrievance\Service\GrievanceApiService
   */
  protected $apiService;

  /**
   * @var \Drupal\global_module\Service\FileUploadService
   */
  protected $fileUploadService;

  /**
   * Constructor.
   *
   * Initializes the form with required services for API communication,
   * file uploads, and caching.
   */
  public function __construct(
    GrievanceApiService $apiService,
    FileUploadService $fileUploadService,
    CacheBackendInterface $cache
  ) {
    $this->apiService = $apiService;
    $this->fileUploadService = $fileUploadService;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   *
   * Creates a new instance of the form with dependency injection.
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('reportgrievance.grievance_api'),
      $container->get('global_module.file_upload_service'),
      $container->get('cache.default')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Returns the unique form ID for this form.
   */
  public function getFormId()
  {
    return 'report_grievance';
  }

  /**
   * {@inheritdoc}
   *
   * Builds the grievance reporting form with dynamic dropdowns,
   * location fields, file upload, and submission controls.
   * Attaches JavaScript library for dynamic loading of options.
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    // Initialize empty dropdowns - populated dynamically via JavaScript
    $grievance_types = [];
    $subtype_options = [];
    $selected_type = $form_state->getValue('grievance_type') ?? '';

    // Grievance Type dropdown - loads options via AJAX
    $form['grievance_type'] = [
      self::TYPE_KEY => 'select',
      '#options' => $grievance_types,
      '#empty_option' => $this->t('Select a Category'),
      '#default_value' => $selected_type,
      self::REQUIRED => TRUE,
      '#validated' => TRUE,
      self::REQUIRED_ERROR => $this->t('Please Select Category'),
      self::ATTRIBUTES_KEY => ['class' => ['form-select', 'grievance-type-select', 'w-full', 'rounded-md', 'border', 'border-gray-300', self::FOCUS_YELLOW_BORDER, self::YELLOW_RING_FOCUS, 'text-gray-700', 'text-base', 'p-2.5'], 'data-endpoint' => '/grievance/types'],
    ];

    // Container for subtype dropdown - updated dynamically
    $form['subtype_wrapper'] = [
      self::TYPE_KEY => 'container',
      self::ATTRIBUTES_KEY => ['id' => 'subtype-wrapper'],
    ];

    $form['subtype_wrapper']['grievance_subtype'] = [
      self::TYPE_KEY => 'select',
      '#options' => $subtype_options,
      '#empty_option' => $this->t('Select Sub Category'),
      '#default_value' => '',
      self::REQUIRED => TRUE,
      '#validated' => TRUE,
      self::REQUIRED_ERROR => $this->t('Please Select Sub Category'),
      self::ATTRIBUTES_KEY => ['class' => ['form-select', 'grievance-subtype-select', 'w-full', 'rounded-md', 'border', 'border-gray-300', self::FOCUS_YELLOW_BORDER, self::YELLOW_RING_FOCUS, 'text-gray-700', 'text-base', 'p-2.5'], 'data-endpoint-template' => '/grievance/subtypes/'],
    ];

    // Remarks text field for grievance description
    $form['remarks'] = [
      self::TYPE_KEY => 'textfield',
      '#title' => $this->t('Remarks'),
      self::REQUIRED => TRUE,
      self::REQUIRED_ERROR => $this->t('Remarks is required.'),
      '#maxlength' => 255,
      self::ATTRIBUTES_KEY => ['placeholder' => $this->t('Remarks'), 'class' => ['form-input', 'w-full', 'rounded-md', 'border', 'border-gray-300', self::FOCUS_YELLOW_BORDER, self::YELLOW_RING_FOCUS, 'text-gray-700', 'text-base', 'p-2.5']],
    ];

    // Address field - populated by geolocation, readonly
    $form['address'] = [
      self::TYPE_KEY => 'textfield',
      '#title' => $this->t('Address'),
      '#maxlength' => 255,
      self::REQUIRED => TRUE,
      self::REQUIRED_ERROR => $this->t('Address is required.'),
      self::ATTRIBUTES_KEY => ['placeholder' => $this->t('Address'), 'class' => ['form-input', 'w-full', 'rounded-md', 'border', 'border-gray-300', self::FOCUS_YELLOW_BORDER, self::YELLOW_RING_FOCUS, 'text-gray-700', 'text-base', 'p-2.5'], 'readonly' => 'readonly'],
    ];

    // File upload field for supporting documents
    $form['upload_file'] = [
      self::TYPE_KEY => 'file',
      self::REQUIRED => FALSE,
      '#limit_validation_errors' => [],
      self::ATTRIBUTES_KEY => ['class' => ['form-input', 'rounded-md', 'border', 'border-gray-300', self::FOCUS_YELLOW_BORDER, self::YELLOW_RING_FOCUS, 'text-gray-700', 'text-base', 'p-2.5']],
    ];

    // Terms agreement checkbox
    $form['agree_terms'] = [
      self::TYPE_KEY => 'checkbox',
      self::REQUIRED => TRUE,
      self::ATTRIBUTES_KEY => ['class' => ['w-6', 'h-6', 'rounded', 'cursor-pointer', 'border', 'border-gray-400']],
    ];

    // Hidden latitude field for geolocation data
    $form['latitude'] = [
      self::TYPE_KEY => 'textfield',
      self::ATTRIBUTES_KEY => [
        'class' => ['lat-input'],
        'readonly' => 'readonly',
        'style' => 'display: none;',
      ],
    ];

    // Hidden longitude field for geolocation data
    $form['longitude'] = [
      self::TYPE_KEY => 'textfield',
      self::ATTRIBUTES_KEY => [
        'class' => ['lng-input'],
        'readonly' => 'readonly',
        'style' => 'display: none;',
      ],
    ];

    // Submit button with styling
    $form['actions'][self::TYPE_KEY] = 'actions';
    $form['actions']['submit'] = [
      self::TYPE_KEY => 'submit',
      '#value' => $this->t('Submit'),
      '#button_type' => 'primary',
      self::ATTRIBUTES_KEY => ['class' => ['lg:h-14', 'lg:w-44', 's:h-10', 'xs:h-10', 'bg-yellow-500', 'text-white', 'text-lg', 'rounded-full', 'px-6', 'py-2', 'hover:bg-yellow-600', 'transition']],
    ];

    // Attach JavaScript library and endpoint settings
    $form['#attached']['library'][] = 'reportgrievance/report_grievance_form';
    $form['#attached']['drupalSettings']['reportgrievance'] = [
      'endpoints' => [
        'types' => '/grievance/types',
        'subtypes' => '/grievance/subtypes/',
      ],
    ];

    // Cache settings and theme configuration
    $form['#cache']['max-age'] = 3600;
    $form['#theme'] = 'report_grievance_form';
    $form[self::ATTRIBUTES_KEY]['enctype'] = 'multipart/form-data';

    return $form;
  }

  /**
   * Validates form submission by checking cached grievance types and subtypes.
   *
   * Ensures selected type and subtype exist in cached data to prevent
   * invalid submissions.
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $grievance_type_id = $form_state->getValue('grievance_type');
    $grievance_subtype_id = $form_state->getValue('grievance_subtype');

    // Load cached grievance types for validation
    $cache = \Drupal::cache()->get('grievance_types');
    $types = $cache ? $cache->data : [];

    // Validate that selected type exists in cache
    if (!isset($types[$grievance_type_id])) {
      $form_state->setErrorByName('grievance_type', $this->t('Invalid grievance type selected.'));
    }

    // Load cached subtypes for the selected type
    $sub_cache = \Drupal::cache()->get('grievance_subtypes_' . $grievance_type_id);
    $subtypes = $sub_cache ? $sub_cache->data : [];

    // Validate that selected subtype exists in cache
    if (!isset($subtypes[$grievance_subtype_id])) {
      $form_state->setErrorByName('grievance_subtype', $this->t('Invalid grievance subtype selected.'));
    }
  }

  /**
   * Submit handler.
   *
   * Processes form submission by handling file uploads, preparing API payload,
   * submitting to grievance service, and redirecting based on response.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $values = $form_state->getValues();
    $image_url = NULL;
    $response_data = [];
    $request = \Drupal::request();
    $vault = \Drupal::service('global_module.vault_config_service');
    $globalVariables = $vault->getGlobalVariables();

    // Handle optional file upload
    if (isset($_FILES['files']['full_path']['upload_file']) && is_uploaded_file($_FILES['files']['tmp_name']['upload_file'])) {
      $upload_response = $this->fileUploadService->uploadFile($request);
      if ($upload_response instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
        $response_data = json_decode($upload_response->getContent(), TRUE);
        if (!empty($response_data['fileName'])) {
          $image_url = $response_data['fileName'];
        } else {
          $this->messenger()->addError($this->t('File upload failed.'));
          // Log upload failure for security monitoring
          \Drupal::logger('reportgrievance')->error('File upload failed during grievance submission. Response: @response', ['@response' => print_r($response_data, TRUE)]);
          return;
        }
      }
    }

    // Get user ID from session data
    $session = $request->getSession();
    $userId = $session->get('api_redirect_result')['userId'] ?? 0;

    // Prepare API payload with form data and file information
    $payload = [
      'address' => $values['address'],
      'remarks' => $values['remarks'] ?? '',
      'isShareAllowed' => FALSE,
      'latitude' => (float)($values['latitude'] ?? ''),
      'longitude' => (float)($values['longitude'] ?? ''),
      'grievanceTypeId' => (int)$values['grievance_type'],
      'grievanceSubTypeId' => (int)$values['grievance_subtype'],
      'tenantCode' => $globalVariables['applicationConfig']['config']['tenantCode'] ?? '',
      'userId' => (int)$userId,
      'files' => [
        [
          'isFileAttached' => !empty($image_url),
          'fileType' => $response_data['fileTypeVal'] ?? '',
          'fileTypeId' => $response_data['fileTypeId'] ?? '',
          'fileUploadedUrl' => $image_url,
        ],
      ],
      'sourceTypeId' => 10,
      'linkedGrievanceId' => '',
      'isGrievanceLinked' => FALSE,
      'requestTypeId' => 1,
    ];

    // Submit grievance to API service
    $response = $this->apiService->sendGrievance($payload);
    \Drupal::logger('reportgrievance')->debug('Grievance submission response: @response', ['@response' => print_r($response, TRUE)]);
    // Handle successful submission
    if (!empty($response['success']) && !empty($response['data']['status'])) {
      $grievance_id = $response['data']['data']; // GV-20251009-371833
      $secret = "my_secret_key";
      $token = hash_hmac('sha256', $grievance_id, $secret);

      // Store secure token mapping for success page
      \Drupal::keyValue('reportgrievance.token_map')->set($token, $grievance_id);

      // Redirect to success page with token
      $url = '/success-grievance/' . $token;
      $form_state->setRedirectUrl(\Drupal\Core\Url::fromUri('internal:' . $url));
      $this->messenger()->addStatus($this->t('Grievance submitted successfully.'));
    } else {
      // Redirect to failure page on submission error
      $form_state->setRedirect('reportgrievance.grievance_failure');
      $this->messenger()->addError($this->t('Submission failed. Please try again later.'));
    }
  }
}
