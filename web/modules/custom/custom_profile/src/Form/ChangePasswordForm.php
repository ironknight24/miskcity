<?php

namespace Drupal\custom_profile\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\custom_profile\Service\PasswordChangeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form that collects old, new, and confirm-new passwords and delegates the
 * actual IDAM password-change logic to PasswordChangeService.
 *
 * On submit the form always redirects to the global status page, passing the
 * outcome (status flag + message) as query parameters so the status page
 * template can render the appropriate success or error state.
 *
 * Shared Tailwind CSS classes are centralised in SHARED_FIELD_CLASSES and
 * Drupal render-array key strings in private constants to reduce repetition
 * across the three password fields.
 */
class ChangePasswordForm extends FormBase
{

  /** @var string Drupal render array key for the #prefix property. */
  private const PREFIX_KEY = '#prefix';

  /** @var string Drupal render array key for the #suffix property. */
  private const SUFFIX_KEY = '#suffix';

  /** @var string Drupal render array key for the #attributes property. */
  private const ATTRIBUTES_KEY = '#attributes';

  /** @var string Drupal render array key for the #type property. */
  private const TYPE_KEY = '#type';

  /** @var string Drupal render array key for the #title property. */
  private const TITLE_KEY = '#title';

  /** @var string Drupal render array key for the #required property. */
  private const REQUIRED_KEY = '#required';

  /**
   * Tailwind utility classes applied to all three password input elements.
   *
   * Defined once here to prevent divergence and simplify future restyling.
   *
   * @var string[]
   */
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
   * The service that handles IDAM SCIM/OAuth2 password-change calls.
   *
   * @var \Drupal\custom_profile\Service\PasswordChangeService
   */
  protected $passwordChangeService;

  /**
   * Constructs a ChangePasswordForm.
   *
   * @param \Drupal\custom_profile\Service\PasswordChangeService $passwordChangeService
   *   The password change service.
   */
  public function __construct(PasswordChangeService $passwordChangeService)
  {
    $this->passwordChangeService = $passwordChangeService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('custom_profile.password_change')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'change_password_form';
  }

  /**
   * {@inheritdoc}
   *
   * Builds three password fields using shared class and key constants to keep
   * the definition concise. The custom change-password Twig theme is applied
   * so the front-end template controls layout independently of Drupal defaults.
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form[self::PREFIX_KEY]              = '<div id="change-password-form-wrapper">';
    $form[self::SUFFIX_KEY]              = '</div>';
    $form[self::ATTRIBUTES_KEY]['class'][] = 'form-sec lg:px-10 text-center lg:text-start s:mb-24 xs:mb-20';

    $form['old_password'] = [
      self::TYPE_KEY       => 'password',
      self::TITLE_KEY      => $this->t('Old Password'),
      self::REQUIRED_KEY   => TRUE,
      self::ATTRIBUTES_KEY => [
        'class' => array_merge(self::SHARED_FIELD_CLASSES, ['text-sm', 'text-medium_dark', 'bg-transparent', 'appearance-none']),
        'placeholder'  => ' ',
        'autocomplete' => 'off',
        'id'           => 'old-password',
      ],
      self::PREFIX_KEY => '<div class="errors-old-password"><div class="relative">',
      self::SUFFIX_KEY => '</div></div>',
    ];

    $form['new_password'] = [
      self::TYPE_KEY       => 'password',
      self::TITLE_KEY      => $this->t('New Password'),
      self::REQUIRED_KEY   => TRUE,
      self::ATTRIBUTES_KEY => [
        'class'     => self::SHARED_FIELD_CLASSES,
        'maxlength' => 10,
        'minlength' => 10,
        'id'        => 'new-password',
        'placeholder' => ' ',
      ],
      self::PREFIX_KEY => '<div class="errors-new-password"><div class="relative">',
      self::SUFFIX_KEY => '</div></div>',
    ];

    $form['confirm_password'] = [
      self::TYPE_KEY       => 'password',
      self::TITLE_KEY      => $this->t('Confirm Password'),
      self::REQUIRED_KEY   => TRUE,
      self::ATTRIBUTES_KEY => [
        'class'       => self::SHARED_FIELD_CLASSES,
        'placeholder' => ' ',
        'id'          => 'confirm-password',
      ],
      self::PREFIX_KEY => '<div class="errors-confirm-password"><div class="relative">',
      self::SUFFIX_KEY => '</div></div>',
    ];

    $form['actions']['submit'] = [
      self::TYPE_KEY => 'submit',
      '#value'       => $this->t('Continue'),
      '#attributes'  => [
        'class' => ['btn', 'btn-warning', 'lg:h-14', 'lg:w-44', 'xs:h-10', 'text-white', 'capitalize', 'text-lg', 'submitBtn', 'engage-btn'],
      ],
    ];

    $form['#theme'] = 'change-password';
    $form['#attached']['library'][] = 'custom_profile/change-password-library';

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Delegates to PasswordChangeService and redirects to the global status
   * page regardless of outcome. Status (1 or 0) and the service message are
   * forwarded as query parameters so the status template can render correctly
   * without a server round-trip.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $oldPass     = $form_state->getValue('old_password');
    $newPass     = $form_state->getValue('new_password');
    $confirmPass = $form_state->getValue('confirm_password');

    $result = $this->passwordChangeService->changePassword($oldPass, $newPass, $confirmPass);

    $status  = !empty($result['status']) ? 1 : 0;
    $message = $result['message'] ?? 'Something went wrong.';

    $form_state->setRedirect('global_module.status', [], [
      'query' => [
        'status'   => $status,
        'message'  => $message,
        'formData' => 'change-password',
      ],
    ]);
  }

}
