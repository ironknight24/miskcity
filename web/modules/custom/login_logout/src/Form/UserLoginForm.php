<?php

namespace Drupal\login_logout\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\login_logout\Service\LoginSubmitHandler;

/**
 * Provides a user login form with email-first validation.
 */
class UserLoginForm extends FormBase {

  public const RETURN_FALSE = 'return false;';
  private const TYPE_KEY = '#type';
  private const TITLE_KEY = '#title';
  private const ATTRIBUTES_KEY = '#attributes';

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The login submit handler.
   *
   * @var \Drupal\login_logout\Service\LoginSubmitHandler
   */
  protected $loginSubmitHandler;

  /**
   * Constructs a new UserLoginForm object.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    LoginSubmitHandler $loginSubmitHandler
  ) {
    $this->currentUser = $currentUser;
    $this->loginSubmitHandler = $loginSubmitHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('login_logout.login_submit_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'user_login_email_first';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    if (!$form_state->isRebuilding() && !$form_state->isSubmitted()) {
      $form_state->set('email_validated', FALSE);
    }
    $form['email'] = [
      self::TYPE_KEY => 'email',
      self::TITLE_KEY => $this->t('Email'),
      self::ATTRIBUTES_KEY => [
        'placeholder' => $this->t('Email'),
        'onpaste' => self::RETURN_FALSE,
        'oncopy' => self::RETURN_FALSE,
        'oncut' => self::RETURN_FALSE,
        'autocomplete' => 'off',
      ],
      '#maxlength' => 254,
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('email'),
    ];

    if ($form_state->get('email_validated')) {
      $form['password'] = [
        self::TYPE_KEY => 'password',
        self::TITLE_KEY => $this->t('Password'),
        self::ATTRIBUTES_KEY => [
          'placeholder' => $this->t('Password'),
          'onpaste' => self::RETURN_FALSE,
          'oncopy' => self::RETURN_FALSE,
          'oncut' => self::RETURN_FALSE,
          'autocomplete' => 'new-password',
        ],
        '#required' => TRUE,
      ];

      $form['login'] = [
        self::TYPE_KEY => 'submit',
        '#value' => $this->t('Login'),
      ];

      $form['forgot_password'] = [
        self::TYPE_KEY => 'link',
        self::TITLE_KEY => $this->t('Forgot Password?'),
        '#name' => 'forgot_button',
        '#url' => Url::fromRoute('login_logout.forgot_password_form'),
        self::ATTRIBUTES_KEY => [
          'class' => ['text-sm', 'text-red-600', 'hover:underline', 'ml-2', 'cursor-pointer'],
          'style' => 'background:none;border:none;padding:0;',
        ],
      ];

      $form['password'][self::ATTRIBUTES_KEY]['class'][] = 'w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-400';
      $form['login'][self::ATTRIBUTES_KEY]['class'][] = 'bg-yellow-500 text-white rounded-xl px-6 py-2 cursor-pointer hover:bg-yellow-600 transition';
    } else {
      $form['check_email'] = [
        self::TYPE_KEY => 'submit',
        '#value' => $this->t('Submit'),
      ];
      $form['check_email'][self::ATTRIBUTES_KEY]['class'][] = 'bg-yellow-500 text-white rounded-xl px-6 py-2 cursor-pointer hover:bg-yellow-600 transition';
    }

    $form['email'][self::ATTRIBUTES_KEY]['class'][] = 'w-full px-4 py-2 border border-gray-300 rounded focus:outline-none focus:ring-2 focus:ring-blue-400';
    $form['#theme'] = 'user_login';

    $form['#attached']['library'][] = 'login_logout/user-login-library';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Prevent duplicate AE logs on form rebuild.
    $request = \Drupal::request();
    if ($request->attributes->get('_ae_logged')) {
      return;
    }
    $request->attributes->set('_ae_logged', TRUE);

    // Keep x-real-ip logic as requested.
    $headers = \Drupal::request()->headers->all();
    $ip = $headers['x-real-ip'][0] ?? 'UNKNOWN';

    $uid = $this->currentUser->id();
    $email = mb_strtolower(trim((string) $form_state->getValue('email')));
    $form_state->setValue('email', $email);
    $username = $email;

    /**
     * AE4 – Abnormal username length
     */
    $ulen = strlen($username);
    if ($ulen < 6 || $ulen > 254) {
      \Drupal::logger('secaudit')->warning(
        'AE4: Abnormal Email length detected. IP: @ip, Length: @length',
        [
          '@uid' => $uid,
          '@ip' => $ip,
          '@length' => $ulen,
        ]
      );
    }

    /**
     * AE6 – Unexpected characters or format in username (email)
     */
    if ($username !== '' && strlen($username) >= 6 && !filter_var($username, FILTER_VALIDATE_EMAIL)) {
      \Drupal::logger('secaudit')->warning(
        'AE6: Invalid email format detected. IP: @ip, Email: @sample',
        [
          '@uid' => $uid,
          '@ip' => $ip,
          '@sample' => substr($username, 0, 50),
        ]
      );
    }

    /**
     * Password-related checks ONLY after email is validated
     */
    if ($form_state->get('email_validated')) {
      $password = (string) $form_state->getValue('password');

      // Run only if password field exists and has value.
      if ($password !== '') {

        /**
         * AE5 – Abnormal password length
         */
        $plen = strlen($password);
        if ($plen < 8 || $plen > 128) {
          \Drupal::logger('secaudit')->warning(
            'AE5: Abnormal password length detected. UID: @uid, IP: @ip, Length: @length',
            [
              '@uid' => $uid,
              '@ip' => $ip,
              '@length' => $plen,
            ]
          );
        }

        /**
         * AE7 – Control characters in password
         */
        if (preg_match('/[\x00-\x1F\x7F]/', $password)) {
          \Drupal::logger('secaudit')->warning(
            'AE7: Control characters detected in password. UID: @uid, IP: @ip',
            [
              '@uid' => $uid,
              '@ip' => $ip,
            ]
          );
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->loginSubmitHandler->handleFormSubmission($form, $form_state);
  }

}
