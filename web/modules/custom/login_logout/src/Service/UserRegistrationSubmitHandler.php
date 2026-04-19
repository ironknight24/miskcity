<?php

namespace Drupal\login_logout\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Handles user registration form submissions.
 */
class UserRegistrationSubmitHandler
{
  use StringTranslationTrait;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\login_logout\Service\UserRegistrationAccountManager
   */
  protected $accountManager;

  /**
   * @var \Drupal\login_logout\Service\UserRegistrationExternalService
   */
  protected $externalRegistrationService;

  /**
   * @var \Drupal\login_logout\Service\OtpService
   */
  protected $otpService;

  /**
   * @var \Drupal\login_logout\Service\RegistrationAuditService
   */
  protected $auditService;

  /**
   * Creates the handler.
   */
  public function __construct(
    MessengerInterface $messenger,
    EntityTypeManagerInterface $entityTypeManager,
    UserRegistrationAccountManager $accountManager,
    UserRegistrationExternalService $externalRegistrationService,
    OtpService $otpService,
    RegistrationAuditService $auditService
  ) {
    $this->messenger = $messenger;
    $this->entityTypeManager = $entityTypeManager;
    $this->accountManager = $accountManager;
    $this->externalRegistrationService = $externalRegistrationService;
    $this->otpService = $otpService;
    $this->auditService = $auditService;
  }

  /**
   * Handles the multi-step registration form submission.
   */
  public function handleFormSubmission(array &$form, FormStateInterface $form_state): ?JsonResponse
  {
    unset($form);

    return match ($form_state->get('phase') ?? 1) {
      1 => $this->handleOtpRequest($form_state),
      2 => $this->handleOtpVerification($form_state),
      3 => $this->handleFinalRegistration($form_state),
      default => NULL,
    };
  }

  /**
   * Handles phase 1 OTP generation and delivery.
   */
  protected function handleOtpRequest(FormStateInterface $form_state): ?JsonResponse
  {
    $data = $this->collectRegistrationData($form_state);
    $this->auditService->logUsernameAnomalies((string) $data['mail']);

    if ($this->emailAlreadyRegistered((string) $data['mail'])) {
      $this->messenger->addError($this->t('Email already registered.'));
      return NULL;
    }

    $form_state->set('user_data', $data);
    $response = $this->processOtpRequest($data, $form_state);

    if (!$response instanceof JsonResponse) {
      $form_state->set('phase', 2);
      $form_state->setRebuild();
    }

    return $response;
  }

  /**
   * Processes OTP throttling and delivery.
   */
  protected function processOtpRequest(array $data, FormStateInterface $form_state): ?JsonResponse
  {
    $rateLimitResponse = $this->otpService->enforceOtpRateLimit((string) $data['mail']);
    if ($rateLimitResponse instanceof JsonResponse) {
      return $rateLimitResponse;
    }

    $otp = $this->otpService->generateOtp();
    $form_state->set('otp_code', $otp);
    return $this->otpService->sendOtpWithLock($data, $otp);
  }

  /**
   * Handles phase 2 OTP verification.
   */
  protected function handleOtpVerification(FormStateInterface $form_state): ?JsonResponse
  {
    if ($form_state->getValue('otp') !== $form_state->get('otp_code')) {
      $this->messenger->addError($this->t('Invalid OTP. Please try again.'));
      $form_state->setRebuild();
      return NULL;
    }

    $form_state->set('phase', 3);
    $form_state->setRebuild();
    return NULL;
  }

  /**
   * Handles phase 3 final registration and login.
   */
  protected function handleFinalRegistration(FormStateInterface $form_state): ?JsonResponse
  {
    $password = (string) $form_state->getValue('password');
    $confirmPassword = (string) $form_state->getValue('confirm_password');
    $this->auditService->logPasswordAnomalies($password);

    if (!$this->passwordsMatch($password, $confirmPassword, $form_state)) {
      return NULL;
    }

    $data = $form_state->get('user_data') ?? [];
    if (!$this->externalRegistrationService->registerApiUser($data)) {
      return NULL;
    }

    $this->externalRegistrationService->registerScimUser($data, $password);
    $this->accountManager->finalizeRegistration($data, $password, $form_state);
    return NULL;
  }

  /**
   * Collects user data from the form.
   */
  protected function collectRegistrationData(FormStateInterface $form_state): array
  {
    return [
      'first_name' => $form_state->getValue('first_name'),
      'last_name' => $form_state->getValue('last_name'),
      'mail' => $form_state->getValue('mail'),
      'country_code' => $form_state->getValue('country_code'),
      'mobile' => $form_state->getValue('mobile'),
    ];
  }

  /**
   * Checks whether a Drupal account already exists for the email.
   */
  protected function emailAlreadyRegistered(string $email): bool
  {
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['mail' => $email]);

    return !empty($users);
  }

  protected function passwordsMatch(string $password, string $confirmPassword, FormStateInterface $form_state): bool
  {
    if ($password === $confirmPassword) {
      return TRUE;
    }

    $this->messenger->addError($this->t('Passwords do not match.'));
    $form_state->setRebuild();
    return FALSE;
  }
}
