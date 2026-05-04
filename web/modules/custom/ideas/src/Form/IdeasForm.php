<?php

namespace Drupal\ideas\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\global_module\Service\FileUploadService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Form class for submitting ideas in the citizen portal.
 *
 * This form allows users to submit ideas with title, author, category, content, and file upload.
 */
class IdeasForm extends FormBase
{
  /**
   * Key for form attributes.
   */
  public const ATTRIBUTES_KEY = '#attributes';

  /**
   * Key for form suffix.
   */
  public const SUFFIX_KEY = '#suffix';

  /**
   * Key for form prefix.
   */
  public const PREFIX_KEY = '#prefix';

  /**
   * Closing div tag.
   */
  public const CLOSE_DIV = '</div>';

  /**
   * Key for form type.
   */
  public const TYPE_KEY = '#type';

  /**
   * Key for form title.
   */
  public const TITLE_KEY = '#title';

  /**
   * Key for required field.
   */
  public const REQUIRED_KEY = '#required';

  /**
   * Placeholder div for relative positioning.
   */
  public const DIV_RELATIVE_PLACEHOLDER = '<div class="relative mb-4">';

  /**
   * Key for attached libraries.
   */
  public const ATTACHED_KEY = '#attached';

  /**
   * File upload service.
   *
   * @var FileUploadService
   */
  protected $fileUploadService;

  /**
   * Current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Constructs the IdeasForm.
   *
   * @param FileUploadService $fileUploadService
   *   The file upload service.
   * @param RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(FileUploadService $fileUploadService, RequestStack $request_stack)
  {
    $this->fileUploadService = $fileUploadService;
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * Creates a new instance of the IdeasForm.
   *
   * @param ContainerInterface $container
   *   The service container.
   *
   * @return static
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('global_module.file_upload_service'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'ideas_form';
  }

  /**
   * Gets the options for idea categories.
   *
   * @return array
   *   Array of category options.
   */
  private function getIdeaCategoryOptions()
  {
    // Static variable to avoid rebuilding in the same request
    static $options = NULL;
    if ($options !== NULL) {
      return $options;
    }

    $cid = 'ideas_form:category_options';
    $cache = \Drupal::cache()->get($cid);
    if ($cache) {
      return $cache->data;
    }

    $options = ['' => $this->t('Select Category')];
    $terms = \Drupal::service('entity_type.manager')->getStorage('taxonomy_term')->loadTree('idea_category');
    foreach ($terms as $term) {
      $options[$term->tid] = $term->name;
    }

    // Cache permanently with taxonomy_term_list tag for automatic invalidation
    \Drupal::cache()->set($cid, $options, CacheBackendInterface::CACHE_PERMANENT, ['taxonomy_term_list']);

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $srcId = FALSE)
  {
    $form[self::PREFIX_KEY] = '<div id="ideas-form-wrapper">';
    $form[self::SUFFIX_KEY] = self::CLOSE_DIV;
    $inputFieldClass = explode(' ', 'px-2.5 pb-2.5 pt-4 text-sm text-medium_dark bg-transparent rounded-lg border border-1 border-gray-300 appearance-none dark:border-gray-600 dark:focus:border-blue-500 focus:focus:border-amber-300 focus:outline-none focus:ring-0 focus:border-yellow-600 peer');

    $form[self::ATTRIBUTES_KEY]['class'][] = 'form-sec p-4 lg:px-10 lg:py-12 bg-white text-center lg:text-start s:mb-24 xs:mb-20';

    $form['first_name'] = [
      self::TYPE_KEY => 'textfield',
      self::TITLE_KEY => $this->t('Title'),
      self::REQUIRED_KEY => TRUE,
      self::ATTRIBUTES_KEY => [
        'minlength' => 2,
        'maxlength' => 50,
        'autocomplete' => 'off',
        'class' => $inputFieldClass,
        'placeholder' => ' ',
      ],
      self::PREFIX_KEY => self::DIV_RELATIVE_PLACEHOLDER,
      self::SUFFIX_KEY => self::CLOSE_DIV
    ];

    $form['author'] = [
      self::TYPE_KEY => 'textfield',
      self::REQUIRED_KEY => TRUE,
      self::TITLE_KEY => $this->t('Author'),
      self::ATTRIBUTES_KEY => [
        'autocomplete' => 'off',
        'class' => $inputFieldClass,
        'placeholder' => ' ',
      ],
      self::PREFIX_KEY => self::DIV_RELATIVE_PLACEHOLDER,
      self::SUFFIX_KEY => self::CLOSE_DIV
    ];

    $form['category_idea'] = [
      self::TYPE_KEY => 'select',
      self::TITLE_KEY => $this->t('Idea Categories'),
      self::REQUIRED_KEY => TRUE,
      // '#title_display' => 'invisible',
      '#options' => $this->getIdeaCategoryOptions(),
      self::ATTRIBUTES_KEY => [
        'class' => array_merge(['select', 'font-Open_Sans', 'font-Open_Sans_Bold', 'text-base'], $inputFieldClass),
        'autocomplete' => 'off',
      ],
      self::PREFIX_KEY => self::DIV_RELATIVE_PLACEHOLDER,
      self::SUFFIX_KEY => self::CLOSE_DIV
    ];

    $form['idea_content'] = [
      self::TYPE_KEY => 'textarea',
      self::TITLE_KEY => $this->t('<div class="font-nevis text-gray-500">Idea Content</div>'),
      self::REQUIRED_KEY => TRUE,
      self::ATTRIBUTES_KEY => [
        'class' => ['peer', 'w-full', 'px-2.5', 'pb-2.5', 'pt-4', 'text-sm', 'text-gray-700', 'bg-transparent', 'rounded-lg', 'border', 'border-gray-300', 'appearance-none', 'focus:outline-none', 'focus:ring-0', 'focus:!border-yellow-500'],
        'autocomplete' => 'off',
        'rows' => 5,
        'placeholder' => '',
      ],
      self::PREFIX_KEY => '<div class="relative mt-4 flex flex-col text-left">',
      self::SUFFIX_KEY => self::CLOSE_DIV
    ];

    $form['upload_file'] = [
      self::TYPE_KEY => 'file',
      self::TITLE_KEY => $this->t('<span class="font-nevis text-gray-500">Upload Picture</span>'),
      '#description' => $this->t('<span class="text-xs">Supported file types: JPG, JPEG, PNG, max size 2MB.</span>'),
      self::REQUIRED_KEY => TRUE,
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg jpeg png pdf'],
        'file_validate_size' => [2 * 1024 * 1024],
      ],
      self::ATTRIBUTES_KEY => [
        'class' => ['peer','w-1/2','lg:max-w-lg','px-2.5','py-2.5','text-sm','text-medium_dark',
          'bg-transparent',
          'rounded-lg',
          'text-base',
          's:text-sm',
          'xs:text-sm',
          'rounded-lg',
          'border',
          'border-gray-300 '
        ]
      ],
      self::PREFIX_KEY => '<div class="relative mb-4 flex flex-col text-left">',
      self::SUFFIX_KEY => self::CLOSE_DIV
    ];

    $form['upload_file_hidden'] = [
      self::TYPE_KEY => 'hidden',
      self::ATTRIBUTES_KEY => ['id' => 'uploaded_file_url'],
    ];

    $form['terms'] = [
      self::TYPE_KEY => 'checkbox',
      // self::TITLE_KEY => $this->t('I agree on <a href="@url" target="_blank">Terms and Conditions</a>', ['@url' => 'https://www.trinitymobility.com/']),
      self::REQUIRED_KEY => TRUE,
      self::PREFIX_KEY => '<div id="checkboxBtn">',
      self::SUFFIX_KEY => self::CLOSE_DIV,
      self::ATTRIBUTES_KEY => [
        'class' => ['checkbox', 'just-validate-success-field', 'border', 'border-2', 's:w-6', 's:h-6', 'xs:w-4', 'xs:h-4'],
      ],
    ];

    $form['actions'] = [
      self::TYPE_KEY => 'actions',
      self::PREFIX_KEY => '<div class="submit-btns btns flex lg:gap-8 mt-5 flex-col sm:flex-row s:gap-5 xs:gap-5">',
      self::SUFFIX_KEY => self::CLOSE_DIV,
    ];

    $form['actions']['submit'] = [
      self::TYPE_KEY => 'submit',
      '#value' => $this->t('Submit'),
      self::ATTRIBUTES_KEY => [
        'class' => ['btn', 'buttoning', 'btn-warning', 'lg:h-14', 'lg:w-44', 'xs:h-10', 'text-white', 'capitalize', 'text-lg', 'font-Open_Sans', 'submitBtn'],
      ],
      '#ajax' => [
        'callback' => '::ajaxSubmitCallback',
        'wrapper' => 'ideas-form-wrapper',
        'effect' => 'fade',
      ],
    ];

    $form['actions']['reset'] = [
      self::TYPE_KEY => 'button',
      '#value' => $this->t('Cancel'),
      self::ATTRIBUTES_KEY => [
        'class' => [
          'btn',
          'bg-transparent',
          'text-black/75',
          'px-14',
          'text-[1.125rem]',
          "font-['Open_Sans']",
          'rounded-[10px]',
          'transition-colors',
          'duration-200',
          'ease-in-out',
          'border',
          'border-black/25',
          'cursor-pointer',
          'inline',
          'font-bold',
          'btn-outline',
          'lg:h-14',
          'lg:w-44',
          'xs:h-10',
          'capitalize',
          'text-medium_dark',
          'button',
          'rounded-lg',
          'cancelBtn'

        ],
        'onclick' => 'window.location.reload()',
      ],
    ];
    $form['#theme'] = 'ideas';
    $form[self::ATTACHED_KEY]['library'][] = 'ideas/ideas-library';
    $form[self::ATTACHED_KEY]['library'][] = 'global_module/ajax_loader';
    $form[self::ATTRIBUTES_KEY]['enctype'] = 'multipart/form-data';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $image_url = $form_state->getValue('upload_file_hidden');

    // Gather form values.
    $title = $form_state->getValue('first_name');
    $author = $form_state->getValue('author');
    $category_id = $form_state->getValue('category_idea');
    $body = $form_state->getValue('idea_content');

    if (empty($title) || empty($author) || empty($category_id) || empty($body)) {
      $this->messenger()->addError($this->t('Please fill in all required fields.'));
      return;
    }

    // Queue the data instead of saving directly.
    try {
      $queue = \Drupal::queue('ideas_create_queue');

      $queue->createItem([
        'title' => $title,
        'author' => $author,
        'category_id' => $category_id,
        'body' => $body,
        'image_url' => $image_url,
        'submitted_at' => \Drupal::time()->getRequestTime(),
        'uid' => \Drupal::currentUser()->id(),
      ]);

      $this->messenger()->addStatus($this->t('Your idea has been submitted and will be processed shortly.'));
    } catch (\Exception $e) {
      \Drupal::logger('ideas_form')->error($e->getMessage());
      $this->messenger()->addError($this->t('An error occurred while queuing your idea.'));
    }
  }

  /**
   * AJAX callback for form submission.
   *
   * @param array $form
   *   The form array.
   * @param FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form array.
   */
  public function ajaxSubmitCallback(array &$form, FormStateInterface $form_state)
  {
    $form[self::ATTACHED_KEY]['drupalSettings']['ideas']['submissionSuccess'] = TRUE;
    return $form;
  }
}
