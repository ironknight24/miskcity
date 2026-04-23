<?php

namespace Drupal\login_logout\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\login_logout\Service\UserRegistrationSubmitHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

class UserRegisterForm extends FormBase
{
  public const RETURN_FALSE = 'return false;';
  private const TYPE_KEY = '#type';
  private const TITLE_KEY = '#title';
  private const REQUIRED_KEY = '#required';
  private const MAXLENGTH_KEY = '#maxlength';
  private const ATTRIBUTES_KEY = '#attributes';
  private const VALUE_KEY = '#value';
  /**
   * Handles the registration submit workflow.
   *
   * @var \Drupal\login_logout\Service\UserRegistrationSubmitHandler
   */
  protected $registrationSubmitHandler;

  public function __construct(UserRegistrationSubmitHandler $registrationSubmitHandler)
  {
    $this->registrationSubmitHandler = $registrationSubmitHandler;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('login_logout.user_registration_submit_handler')
    );
  }

  public function getFormId()
  {
    return 'user_register_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $tempstore = \Drupal::service('tempstore.private')->get('login_logout');
    $email = $tempstore->get('registration_email');
    $phase = $form_state->get('phase') ?? 1;

    // Classes reused.
    $input_classes = ['form-input', 'w-full', 'rounded-md', 'border', 'border-gray-300', 'focus:border-yellow-500', 'focus:ring-yellow-500', 'text-gray-700', 'text-base', 'p-2.5'];
    $select_classes = ['form-select', 'w-full', 'rounded-md', 'border', 'border-gray-300', 'focus:border-yellow-500', 'focus:ring-yellow-500', 'text-gray-700', 'text-base', 'p-2.5'];
    $button_classes = ['bg-yellow-500', 'hover:bg-yellow-600', 'text-white', 'font-semibold', 'py-2', 'px-4', 'rounded-2xl', 'transition-all'];

    switch ($phase) {
      case 1:
        $form['first_name'] = [
          self::TYPE_KEY => 'textfield',
          self::TITLE_KEY => $this->t('First Name'),
          self::REQUIRED_KEY => TRUE,
          self::MAXLENGTH_KEY => 255,
          self::ATTRIBUTES_KEY => ['class' => $input_classes],
        ];
        $form['last_name'] = [
          self::TYPE_KEY => 'textfield',
          self::TITLE_KEY => $this->t('Last Name'),
          self::REQUIRED_KEY => TRUE,
          self::MAXLENGTH_KEY => 255,
          self::ATTRIBUTES_KEY => ['class' => $input_classes],
        ];
        $form['mail'] = [
          self::TYPE_KEY => 'email',
          self::TITLE_KEY => $this->t('Email'),
          '#default_value' => $email,
          self::REQUIRED_KEY => TRUE,
          self::MAXLENGTH_KEY => 254,
          self::ATTRIBUTES_KEY => ['class' => $input_classes],
        ];
        $form['country_code'] = [
          self::TYPE_KEY => 'select',
          self::TITLE_KEY => $this->t('Country Code'),
          self::REQUIRED_KEY => TRUE,
          '#options' => [
            '+91' => '+91 (India)',
            '+1' => '+1 (USA)',
            '+44' => '+44 (UK)',
          ],
          '#default_value' => '+91',
          self::ATTRIBUTES_KEY => [
            'class' => $select_classes,
            'autocomplete' => 'off',
          ],
        ];
        $form['mobile'] = [
          self::TYPE_KEY => 'tel',
          self::TITLE_KEY => $this->t('Mobile Number'),
          self::REQUIRED_KEY => TRUE,
          self::MAXLENGTH_KEY => 10,
          self::ATTRIBUTES_KEY => [
            'class' => $input_classes,
            'pattern' => '[0-9]{10}',
            'title' => $this->t('Enter a valid mobile number'),
            'oninput' => 'this.value = this.value.replace(/[^0-9]/g, "").slice(0,10)',
          ],
        ];
        $form['submit'] = [
          self::TYPE_KEY => 'submit',
          self::VALUE_KEY => $this->t('Send OTP'),
          self::ATTRIBUTES_KEY => ['class' => $button_classes],
        ];
        break;

      case 2:
        $form['otp'] = [
          self::TYPE_KEY => 'textfield',
          self::TITLE_KEY => $this->t('Enter OTP'),
          self::REQUIRED_KEY => TRUE,
          self::ATTRIBUTES_KEY => [
            'maxlength' => 6,
            'class' => $input_classes,
            'onpaste' => self::RETURN_FALSE,
            'oncopy' => self::RETURN_FALSE,
            'oncut' => self::RETURN_FALSE,
            'autocomplete' => 'off',
            'inputmode' => 'numeric',
            'pattern' => '[0-9]*',
          ],
        ];
        $form['submit'] = [
          self::TYPE_KEY => 'submit',
          self::VALUE_KEY => $this->t('Verify OTP'),
          self::ATTRIBUTES_KEY => ['class' => $button_classes],
        ];
        break;

      case 3:
        $form['password'] = [
          self::TYPE_KEY => 'password',
          self::TITLE_KEY => $this->t('Password'),
          self::REQUIRED_KEY => TRUE,
          self::ATTRIBUTES_KEY => [
            'class' => $input_classes,
            'onpaste' => self::RETURN_FALSE,
            'oncopy' => self::RETURN_FALSE,
            'oncut' => self::RETURN_FALSE,
            'autocomplete' => 'new-password',
          ],
        ];
        $form['confirm_password'] = [
          self::TYPE_KEY => 'password',
          self::TITLE_KEY => $this->t('Confirm Password'),
          self::REQUIRED_KEY => TRUE,
          self::ATTRIBUTES_KEY => [
            'class' => $input_classes,
            'onpaste' => self::RETURN_FALSE,
            'oncopy' => self::RETURN_FALSE,
            'oncut' => self::RETURN_FALSE,
            'autocomplete' => 'new-password',
          ],
        ];
        $form['submit'] = [
          self::TYPE_KEY => 'submit',
          self::VALUE_KEY => $this->t('Register'),
          self::ATTRIBUTES_KEY => ['class' => $button_classes],
        ];
        break;

      default:
        break;
    }

    $form['#theme'] = 'user_register';
    $form['#attached']['library'][] = 'login_logout/user-register-library';

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    return $this->registrationSubmitHandler->handleFormSubmission($form, $form_state);
  }
}
