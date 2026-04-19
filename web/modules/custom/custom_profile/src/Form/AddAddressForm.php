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

class AddAddressForm extends FormBase
{
    private const TYPE_KEY = '#type';
    private const TITLE_KEY = '#title';
    private const REQUIRED_KEY = '#required';
    private const REQUIRED_ERROR_KEY = '#required_error';
    private const DEFAULT_VALUE_KEY = '#default_value';
    private const ATTRIBUTES_KEY = '#attributes';
    private const INPUT_CLASSES = ['form-input', 'w-full', 'rounded-md', 'border', 'border-gray-300', 'focus:border-yellow-500', 'focus:ring-yellow-500', 'text-gray-700', 'text-base', 'p-2.5'];
    /**
     * The HTTP client.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $httpClient;
    protected $node;

    public function __construct(ClientInterface $http_client)
    {
        $this->httpClient = $http_client;
    }

    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('http_client')
        );
    }
    public function getFormId()
    {
        return 'add_address__form';
    }

    public function buildForm(array $form, FormStateInterface $form_state, $node = NULL)
    {
        if ($node instanceof Node && $node->bundle() == 'add_address') {
            $this->node = $node;
        }

        $defaults = [
            'postal_code' => '',
            'flat' => '',
            'area' => '',
            'landmark' => '',
            'country' => '',
            'address_type' => '',
        ];

        if ($this->node) {
            $defaults = [
                'postal_code' => $this->node->get('field_postal_code')->value,
                'flat' => $this->node->get('field_street_and_house_no')->value, // If you have a field for this
                'area' => $this->node->get('field_area_address')->value,
                'landmark' => $this->node->get('field_landmark')->value,
                'country' => $this->node->get('field_address_country')->value,
                'address_type' => $this->node->get('field_address_type')->value,
            ];
        }

        $form['#is_edit'] = $node instanceof \Drupal\node\Entity\Node;

        $form['postal_code'] = [
            self::TYPE_KEY => 'textfield',
            self::TITLE_KEY => $this->t('Postal'),
            self::REQUIRED_KEY => TRUE,
            self::REQUIRED_ERROR_KEY => $this->t("Postal code is required"),
            self::DEFAULT_VALUE_KEY => $defaults['postal_code'],
            '#maxlength' => 6,
            self::ATTRIBUTES_KEY => [
                'maxlength' => 6,
                'placeholder' => $this->t('Postal'),
                'class' => self::INPUT_CLASSES,
            ],
            '#element_validate' => [[self::class, 'validatePostalCode']],
        ];

        $form['flat'] = [
            self::TYPE_KEY => 'textfield',
            self::TITLE_KEY => $this->t('Flat, House no., Building, Company, Apartment'),
            self::DEFAULT_VALUE_KEY => $defaults['flat'],
            self::ATTRIBUTES_KEY => [
                'placeholder' => $this->t('Flat, House no., Building, Company, Apartment'),
                'class' => self::INPUT_CLASSES,
            ],
            self::REQUIRED_KEY => TRUE,
            self::REQUIRED_ERROR_KEY => $this->t('Flat is required'),
        ];

        $form['area'] = [
            self::TYPE_KEY => 'textfield',
            self::DEFAULT_VALUE_KEY => $defaults['area'],
            self::TITLE_KEY => $this->t('Area, Colony, Street Sector, Town/City'),
            self::ATTRIBUTES_KEY => [
                'placeholder' => $this->t('Area, Colony, Street Sector, Town/City'),
                'class' => self::INPUT_CLASSES,
            ],
            self::REQUIRED_KEY => TRUE,
            self::REQUIRED_ERROR_KEY => $this->t("Area is required"),
        ];

        $form['landmark'] = [
            self::TYPE_KEY => 'textfield',
            self::DEFAULT_VALUE_KEY => $defaults['landmark'],
            self::TITLE_KEY => $this->t('Landmark e.g. near Apollo Hospital'),
            self::ATTRIBUTES_KEY => [
                'placeholder' => $this->t('Landmark e.g. near Apollo Hospital'),
                'class' => self::INPUT_CLASSES,
            ],
            self::REQUIRED_KEY => TRUE,
            self::REQUIRED_ERROR_KEY => $this->t('Landmark is required')
        ];

        $form['country'] = [
            self::TYPE_KEY => 'textfield',
            self::DEFAULT_VALUE_KEY => $defaults['country'],
            self::TITLE_KEY => $this->t('Country'),
            self::ATTRIBUTES_KEY => [
                'placeholder' => $this->t('Country'),
                'class' => self::INPUT_CLASSES,
            ],
            self::REQUIRED_KEY => TRUE,
            self::REQUIRED_ERROR_KEY => $this->t('Country is required.')
        ];

        $form['address_type'] = [
            self::TYPE_KEY => 'select',
            self::REQUIRED_KEY => TRUE,
            self::REQUIRED_ERROR_KEY => $this->t('Please select any address type'),
            self::DEFAULT_VALUE_KEY => $defaults['address_type'],
            '#empty_option' => $this->t('Select your Address Type'),
            '#options' => [
                // '' => $this->t('- Select -'),
                'home' => $this->t('Home'),
                'office' => $this->t('Office'),
                'other' => $this->t('Other'),
            ],
            self::ATTRIBUTES_KEY => [
                'class' => ['form-select', 'w-full', 'rounded-md', 'border', 'border-gray-300', 'focus:border-yellow-500', 'focus:ring-yellow-500', 'text-gray-700', 'text-base', 'p-2.5'],
            ],
        ];

        $form['actions']['#type'] = 'actions';
        $form['actions']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Submit'),
            '#button_type' => 'primary',
            '#attributes' => [
                'class' => ['bg-yellow-500', 'text-white', 'rounded-full', 'px-6', 'py-2', 'hover:bg-yellow-600', 'transition'],
            ],
        ];

        // Attach theme and Tailwind-based
        $form['#attributes']['class'][] = 'cv-validate-before-ajax';
        $form['#theme'] = 'add_address_form';
        $form['#attached']['library'][] = 'custom_profile/add_address_form';

        return $form;
    }
    public static function access(NodeInterface $node, AccountInterface $account)
    {
        // Check if this node is of the expected content type.
        if ($node->bundle() !== 'add_address') {
            return AccessResult::forbidden();
        }

        // Allow access only if the user can update this node.
        return AccessResult::allowedIf($node->access('update', $account));
    }


    /**
     * Custom postal code validator.
     */
    public static function validatePostalCode(array &$element, FormStateInterface $form_state, array &$complete_form): void
    {
        $value = $element['#value'];
        if (!preg_match('/^\d{6}$/', $value)) {
            $form_state->setError($element, t('Postal code must be 6 digits'));
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $postal = $form_state->getValue('postal_code');
        $area = $form_state->getValue('area');
        $landmark = $form_state->getValue('landmark');
        $country = $form_state->getValue('country');
        $addressType = $form_state->getValue('address_type');
        $flat = $form_state->getValue('flat');

        if ($this->node) {
            // Edit mode
            $node = $this->node;
            $node->set('field_area_address', $area);
            $node->set('field_postal_code', $postal);
            $node->set('field_street_and_house_no', $flat);
            $node->set('field_landmark', $landmark);
            $node->set('field_address_country', $country);
            $node->set('field_address_type', $addressType);
        } else {
            // Create mode
            $node = Node::create([
                'type' => 'add_address',
                'title' => 'Address for user ' . \Drupal::currentUser()->id(),
                'field_postal_code' => $postal,
                'field_street_and_house_no' => $area,
                'field_landmark' => $landmark,
                'field_address_country' => $country,
                'field_address_type' => $addressType,
                'field_area_address' => $flat,
                'uid' => \Drupal::currentUser()->id(),
                'status' => 1,
            ]);
        }

        $node->save();

        \Drupal::messenger()->addMessage($this->t('Address saved successfully.'));
        $form_state->setRedirect('view.manage_address.page_1');
    }
}
