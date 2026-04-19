<?php

namespace Drupal\custom_profile\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\custom_profile\Service\PasswordChangeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ChangePasswordForm extends FormBase
{
  private const PREFIX_KEY = '#prefix';
  private const SUFFIX_KEY = '#suffix';
  private const ATTRIBUTES_KEY = '#attributes';
  private const TYPE_KEY = '#type';
  private const TITLE_KEY = '#title';
  private const REQUIRED_KEY = '#required';
  private const SHARED_FIELD_CLASSES = [
    'peer',
    'w-full',
    'lg:max-w-lg',
    'text-base',
    's:text-sm',
    'xs:text-sm',
    'rounded-lg',
    'border',
    'border-gray-300',
    'px-2.5',
    'pb-2.5',
    'pt-4',
  ];

  /**
   * The password change service.
   *
   * @var \Drupal\custom_profile\Service\PasswordChangeService
   */
  protected $passwordChangeService;

  public function __construct(PasswordChangeService $passwordChangeService)
  {
    $this->passwordChangeService = $passwordChangeService;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('custom_profile.password_change')
    );
  }

  public function getFormId()
  {
    return 'change_password_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form[self::PREFIX_KEY] = '<div id="change-password-form-wrapper">';
    $form[self::SUFFIX_KEY] = '</div>';
    $form[self::ATTRIBUTES_KEY]['class'][] = 'form-sec lg:px-10 text-center lg:text-start s:mb-24 xs:mb-20';

    // Old password.
    $form['old_password'] = [
      self::TYPE_KEY => 'password',
      self::TITLE_KEY => $this->t('Old Password'),
      self::REQUIRED_KEY => TRUE,
      self::ATTRIBUTES_KEY => [
        'class' => array_merge(self::SHARED_FIELD_CLASSES, ['text-sm', 'text-medium_dark', 'bg-transparent', 'appearance-none']),
        'placeholder' => ' ',
        'autocomplete' => 'off',
        'id' => 'old-password',
      ],
      self::PREFIX_KEY => '<div class="errors-old-password"><div class="relative">',
      self::SUFFIX_KEY => '</div></div>',
    ];

    // New password.
    $form['new_password'] = [
      self::TYPE_KEY => 'password',
      self::TITLE_KEY => $this->t('New Password'),
      self::REQUIRED_KEY => TRUE,
      self::ATTRIBUTES_KEY => [
        'class' => self::SHARED_FIELD_CLASSES,
        'maxlength' => 10,
        'minlength' => 10,
        'id' => 'new-password',
        'placeholder' => ' ',
      ],
      self::PREFIX_KEY => '<div class="errors-new-password"><div class="relative">',
      self::SUFFIX_KEY => '</div></div>',
    ];

    // Confirm password.
    $form['confirm_password'] = [
      self::TYPE_KEY => 'password',
      self::TITLE_KEY => $this->t('Confirm Password'),
      self::REQUIRED_KEY => TRUE,
      self::ATTRIBUTES_KEY => [
        'class' => self::SHARED_FIELD_CLASSES,
        'placeholder' => ' ',
        'id' => 'confirm-password',
      ],
      self::PREFIX_KEY => '<div class="errors-confirm-password"><div class="relative">',
      self::SUFFIX_KEY => '</div></div>',
    ];

    // Submit button.
    $form['actions']['submit'] = [
      self::TYPE_KEY => 'submit',
      '#value' => $this->t('Continue'),
      '#attributes' => [
        'class' => ['btn', 'btn-warning', 'lg:h-14', 'lg:w-44', 'xs:h-10', 'text-white', 'capitalize', 'text-lg', 'submitBtn', 'engage-btn'],
      ],
    ];

    $form['#theme'] = 'change-password';
    $form['#attached']['library'][] = 'custom_profile/change-password-library';

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $oldPass     = $form_state->getValue('old_password');
    $newPass     = $form_state->getValue('new_password');
    $confirmPass = $form_state->getValue('confirm_password');

    $result = $this->passwordChangeService->changePassword($oldPass, $newPass, $confirmPass);

    $status  = !empty($result['status']) ? 1 : 0;
    $message = $result['message'] ?? 'Something went wrong.';

    // Always redirect to status page
    $form_state->setRedirect('global_module.status', [], [
      'query' => [
        'status'   => $status,
        'message'  => $message,
        'formData' => 'change-password',
      ],
    ]);
  }
}
