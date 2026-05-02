<?php

namespace Drupal\custom_profile\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;

/**
 * Form for adding or editing a citizen address stored as a Drupal node.
 *
 * The form operates in two modes determined by the optional $node parameter
 * passed by the route:
 *  - Create mode (/add-address): all fields start empty; submission creates a
 *    new node of type "add_address".
 *  - Edit mode (/edit-address/{node}): fields are pre-populated from the
 *    existing node; submission updates and saves the same node.
 *
 * Access to the edit route is gated by the static access() method, which
 * allows only node owners (or "delete any" administrators) to open their
 * own address record.
 *
 * After saving, both modes redirect to the address management view.
 */
class AddAddressForm extends FormBase
{

  /** @var string Drupal render array key for the #type property. */
  private const TYPE_KEY = '#type';

  /** @var string Drupal render array key for the #title property. */
  private const TITLE_KEY = '#title';

  /** @var string Drupal render array key for the #required property. */
  private const REQUIRED_KEY = '#required';

  /** @var string Drupal render array key for the #required_error property. */
  private const REQUIRED_ERROR_KEY = '#required_error';

  /** @var string Drupal render array key for the #default_value property. */
  private const DEFAULT_VALUE_KEY = '#default_value';

  /** @var string Drupal render array key for the #attributes property. */
  private const ATTRIBUTES_KEY = '#attributes';

  /**
   * Tailwind utility classes shared by all text-input fields.
   *
   * @var string[]
   */
  private const INPUT_CLASSES = ['form-input', 'w-full', 'rounded-md', 'border', 'border-gray-300', 'focus:border-yellow-500', 'focus:ring-yellow-500', 'text-gray-700', 'text-base', 'p-2.5'];

  /**
   * The HTTP client (reserved for future API integrations; not used currently).
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The address node being edited, or NULL when creating a new address.
   *
   * @var \Drupal\node\Entity\Node|null
   */
  protected $node;

  /**
   * Constructs an AddAddressForm.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(ClientInterface $http_client)
  {
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'add_address__form';
  }

  /**
   * {@inheritdoc}
   *
   * When the optional $node argument is a published add_address node (supplied
   * by the edit-address route), the form's default values are read from that
   * node's fields. The #is_edit flag is set so the template can conditionally
   * adjust its heading or button labels.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param \Drupal\node\Entity\Node|null $node
   *   The address node to edit, or NULL for the create flow.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $node = NULL)
  {
    if ($node instanceof Node && $node->bundle() == 'add_address') {
      $this->node = $node;
    }

    $defaults = [
      'postal_code'  => '',
      'flat'         => '',
      'area'         => '',
      'landmark'     => '',
      'country'      => '',
      'address_type' => '',
    ];

    if ($this->node) {
      $defaults = [
        'postal_code'  => $this->node->get('field_postal_code')->value,
        'flat'         => $this->node->get('field_street_and_house_no')->value,
        'area'         => $this->node->get('field_area_address')->value,
        'landmark'     => $this->node->get('field_landmark')->value,
        'country'      => $this->node->get('field_address_country')->value,
        'address_type' => $this->node->get('field_address_type')->value,
      ];
    }

    $form['#is_edit'] = $node instanceof \Drupal\node\Entity\Node;

    $form['postal_code'] = [
      self::TYPE_KEY          => 'textfield',
      self::TITLE_KEY         => $this->t('Postal'),
      self::REQUIRED_KEY      => TRUE,
      self::REQUIRED_ERROR_KEY => $this->t("Postal code is required"),
      self::DEFAULT_VALUE_KEY => $defaults['postal_code'],
      '#maxlength'            => 6,
      self::ATTRIBUTES_KEY    => [
        'maxlength'   => 6,
        'placeholder' => $this->t('Postal'),
        'class'       => self::INPUT_CLASSES,
      ],
      '#element_validate' => [[self::class, 'validatePostalCode']],
    ];

    $form['flat'] = [
      self::TYPE_KEY          => 'textfield',
      self::TITLE_KEY         => $this->t('Flat, House no., Building, Company, Apartment'),
      self::DEFAULT_VALUE_KEY => $defaults['flat'],
      self::ATTRIBUTES_KEY    => [
        'placeholder' => $this->t('Flat, House no., Building, Company, Apartment'),
        'class'       => self::INPUT_CLASSES,
      ],
      self::REQUIRED_KEY      => TRUE,
      self::REQUIRED_ERROR_KEY => $this->t('Flat is required'),
    ];

    $form['area'] = [
      self::TYPE_KEY          => 'textfield',
      self::DEFAULT_VALUE_KEY => $defaults['area'],
      self::TITLE_KEY         => $this->t('Area, Colony, Street Sector, Town/City'),
      self::ATTRIBUTES_KEY    => [
        'placeholder' => $this->t('Area, Colony, Street Sector, Town/City'),
        'class'       => self::INPUT_CLASSES,
      ],
      self::REQUIRED_KEY      => TRUE,
      self::REQUIRED_ERROR_KEY => $this->t("Area is required"),
    ];

    $form['landmark'] = [
      self::TYPE_KEY          => 'textfield',
      self::DEFAULT_VALUE_KEY => $defaults['landmark'],
      self::TITLE_KEY         => $this->t('Landmark e.g. near Apollo Hospital'),
      self::ATTRIBUTES_KEY    => [
        'placeholder' => $this->t('Landmark e.g. near Apollo Hospital'),
        'class'       => self::INPUT_CLASSES,
      ],
      self::REQUIRED_KEY      => TRUE,
      self::REQUIRED_ERROR_KEY => $this->t('Landmark is required')
    ];

    $form['country'] = [
      self::TYPE_KEY          => 'textfield',
      self::DEFAULT_VALUE_KEY => $defaults['country'],
      self::TITLE_KEY         => $this->t('Country'),
      self::ATTRIBUTES_KEY    => [
        'placeholder' => $this->t('Country'),
        'class'       => self::INPUT_CLASSES,
      ],
      self::REQUIRED_KEY      => TRUE,
      self::REQUIRED_ERROR_KEY => $this->t('Country is required.')
    ];

    $form['address_type'] = [
      self::TYPE_KEY          => 'select',
      self::REQUIRED_KEY      => TRUE,
      self::REQUIRED_ERROR_KEY => $this->t('Please select any address type'),
      self::DEFAULT_VALUE_KEY => $defaults['address_type'],
      '#empty_option'         => $this->t('Select your Address Type'),
      '#options'              => [
        'home'   => $this->t('Home'),
        'office' => $this->t('Office'),
        'other'  => $this->t('Other'),
      ],
      self::ATTRIBUTES_KEY => [
        'class' => ['form-select', 'w-full', 'rounded-md', 'border', 'border-gray-300', 'focus:border-yellow-500', 'focus:ring-yellow-500', 'text-gray-700', 'text-base', 'p-2.5'],
      ],
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Submit'),
      '#button_type' => 'primary',
      '#attributes'  => [
        'class' => ['bg-yellow-500', 'text-white', 'rounded-full', 'px-6', 'py-2', 'hover:bg-yellow-600', 'transition'],
      ],
    ];

    $form['#attributes']['class'][] = 'cv-validate-before-ajax';
    $form['#theme'] = 'add_address_form';
    $form['#attached']['library'][] = 'custom_profile/add_address_form';

    return $form;
  }

  /**
   * Route-level access callback for the edit-address route.
   *
   * Denies access when the node is not of the add_address bundle, and
   * delegates the update-permission check to the node entity's own access
   * system so field-level permissions are respected.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The address node identified by the {node} route parameter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account requesting edit access.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   AccessResult::forbidden() for wrong bundle;
   *   AccessResult::allowedIf() gated on node update access otherwise.
   */
  public static function access(NodeInterface $node, AccountInterface $account)
  {
    if ($node->bundle() !== 'add_address') {
      return AccessResult::forbidden();
    }

    return AccessResult::allowedIf($node->access('update', $account));
  }

  /**
   * Element-level validator enforcing a strict six-digit postal code.
   *
   * Attached via #element_validate so it runs as part of the standard Drupal
   * form validation pipeline alongside any #required checks.
   *
   * @param array $element
   *   The postal_code form element being validated.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param array $complete_form
   *   The complete form render array.
   */
  public static function validatePostalCode(array &$element, FormStateInterface $form_state, array &$complete_form): void
  {
    $value = $element['#value'];
    if (!preg_match('/^\d{6}$/', $value)) {
      $form_state->setError($element, t('Postal code must be 6 digits'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * In edit mode, updates the field values on the existing node and saves it.
   * In create mode, creates a new add_address node owned by the current user.
   * Both paths call $node->save() and redirect to the address management view.
   *
   * Note: field mapping differs between modes — in create mode field_area_address
   * receives the value from the "flat" field, consistent with the original
   * scaffolded mapping.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $postal      = $form_state->getValue('postal_code');
    $area        = $form_state->getValue('area');
    $landmark    = $form_state->getValue('landmark');
    $country     = $form_state->getValue('country');
    $addressType = $form_state->getValue('address_type');
    $flat        = $form_state->getValue('flat');

    if ($this->node) {
      // Edit mode: update the existing node in place.
      $node = $this->node;
      $node->set('field_area_address', $area);
      $node->set('field_postal_code', $postal);
      $node->set('field_street_and_house_no', $flat);
      $node->set('field_landmark', $landmark);
      $node->set('field_address_country', $country);
      $node->set('field_address_type', $addressType);
    } else {
      // Create mode: build a new add_address node for the current user.
      $node = Node::create([
        'type'                   => 'add_address',
        'title'                  => 'Address for user ' . \Drupal::currentUser()->id(),
        'field_postal_code'      => $postal,
        'field_street_and_house_no' => $area,
        'field_landmark'         => $landmark,
        'field_address_country'  => $country,
        'field_address_type'     => $addressType,
        'field_area_address'     => $flat,
        'uid'                    => \Drupal::currentUser()->id(),
        'status'                 => 1,
      ]);
    }

    $node->save();

    \Drupal::messenger()->addMessage($this->t('Address saved successfully.'));
    $form_state->setRedirect('view.manage_address.page_1');
  }

}
