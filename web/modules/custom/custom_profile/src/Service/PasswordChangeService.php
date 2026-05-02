<?php

namespace Drupal\custom_profile\Service;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApiHttpClientService;

/**
 * Orchestrates the IDAM-backed password-change workflow.
 *
 * The change flow involves three distinct external calls:
 *  1. A SCIM GET to locate the user by email address (getScimUserId).
 *  2. An OAuth2 token request with the old password to prove it is correct
 *     (isOldPasswordValid).
 *  3. A SCIM PATCH to update the password field (via apiHttpClientService).
 *
 * The public changePassword() method coordinates these steps and returns a
 * status/message pair consumed by ChangePasswordForm for redirect construction.
 */
class PasswordChangeService
{

  /**
   * HTTPS scheme prefix prepended to all IDAM endpoint URLs.
   *
   * Centralised here so callers never construct bare HTTP links accidentally.
   */
  public const SECURE_LINK = "https://";

  /**
   * Provides access to global variables such as IDAM configuration.
   *
   * @var \Drupal\global_module\Service\GlobalVariablesService
   */
  protected $globalVariables;

  /**
   * Logger channel for the "change_password" log category.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The currently authenticated Drupal user, used to read the email address.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The session object, reserved for future session-invalidation on success.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  protected $session;

  /**
   * Provides Vault-stored configuration including the IDAM hostname.
   *
   * @var \Drupal\global_module\Service\VaultConfigService
   */
  protected $vaultConfigService;

  /**
   * HTTP client for IDAM API calls (SCIM and OAuth2 token endpoint).
   *
   * @var \Drupal\global_module\Service\ApiHttpClientService
   */
  protected $apiHttpClientService;

  /**
   * Constructs a PasswordChangeService.
   *
   * @param \Drupal\global_module\Service\GlobalVariablesService $globalVariables
   *   The global variables service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory; the "change_password" channel is obtained here.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user proxy.
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The session service.
   * @param \Drupal\global_module\Service\VaultConfigService $vaultConfigService
   *   The Vault configuration service.
   * @param \Drupal\global_module\Service\ApiHttpClientService $apiHttpClientService
   *   The IDAM-capable HTTP client service.
   */
  public function __construct(
    GlobalVariablesService $globalVariables,
    LoggerChannelFactoryInterface $loggerFactory,
    AccountProxyInterface $currentUser,
    SessionInterface $session,
    VaultConfigService $vaultConfigService,
    ApiHttpClientService $apiHttpClientService
  ) {
    $this->globalVariables = $globalVariables;
    $this->logger = $loggerFactory->get('change_password');
    $this->currentUser = $currentUser;
    $this->session = $session;
    $this->vaultConfigService = $vaultConfigService;
    $this->apiHttpClientService = $apiHttpClientService;
  }

  /**
   * Executes the full password-change workflow.
   *
   * Returns early with an error message if the new and confirm passwords
   * differ. Otherwise, resolves the IDAM user ID via SCIM, validates the old
   * password through the OAuth2 token endpoint, and issues a SCIM PATCH to
   * set the new password.
   *
   * @param string $oldPass
   *   The user's current password as entered in the form.
   * @param string $newPass
   *   The desired new password.
   * @param string $confirmPass
   *   Confirmation of the new password; must equal $newPass.
   *
   * @return array
   *   An associative array with keys:
   *   - 'status' (bool): TRUE on successful password update.
   *   - 'message' (string): A human-readable outcome message.
   */
  public function changePassword(string $oldPass, string $newPass, string $confirmPass): array
  {
    $result = [
      'status' => FALSE,
      'message' => 'Password not updated!',
    ];

    try {
      $mismatchMessage = $this->getPasswordMismatchMessage($newPass, $confirmPass);
      if ($mismatchMessage !== NULL) {
        $result['message'] = $mismatchMessage;
      } else {
        $email = $this->currentUser->getEmail();
        $idamconfig = $this->getIdamConfig();
        $idamUserId = $this->getScimUserId($email, $idamconfig);

        if ($idamUserId === NULL) {
          $result['message'] = 'User not found in SCIM.';
        } elseif (!$this->isOldPasswordValid($email, $oldPass, $idamconfig)) {
          $result['message'] = 'Old password not matching!';
        } else {
          $resPass = $this->apiHttpClientService->postIdamAuth(
            self::SECURE_LINK . $idamconfig . '/scim2/Users/' . $idamUserId,
            $this->buildPasswordUpdatePayload($newPass),
            'PATCH'
          );

          $result['message'] = $this->resolvePasswordChangeMessage($resPass, $email, $result['message']);
          if (
            empty($resPass['error'])
            && !empty($resPass['emails'][0])
            && $resPass['emails'][0] === $email
          ) {
            $result['status'] = TRUE;
          }
        }
      }
    } catch (\Exception $e) {
      $this->logger->error(
        'Exception during password change: @msg',
        ['@msg' => $e->getMessage()]
      );
      $result['message'] = 'Unexpected error occurred.';
    }

    return $result;
  }

  /**
   * Returns an error message if the new and confirm passwords differ.
   *
   * @param string $newPass
   *   The new password value.
   * @param string $confirmPass
   *   The confirmation value.
   *
   * @return string|null
   *   A mismatch error string, or NULL when the passwords match.
   */
  protected function getPasswordMismatchMessage(string $newPass, string $confirmPass): ?string
  {
    return $newPass !== $confirmPass
      ? 'New password and confirm password do not match.'
      : NULL;
  }

  /**
   * Reads the IDAM hostname from Vault-stored application configuration.
   *
   * @return string
   *   The IDAM hostname (without scheme), e.g. "idam.example.com".
   */
  protected function getIdamConfig(): string
  {
    return $this->vaultConfigService
      ->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
  }

  /**
   * Looks up the SCIM user ID for the given email address.
   *
   * Queries the SCIM /Users endpoint with an "emails eq" filter. The first
   * result's id field is returned, or NULL when no matching user is found.
   *
   * @param string $email
   *   The user's email address.
   * @param string $idamconfig
   *   The IDAM hostname (without scheme).
   *
   * @return string|null
   *   The SCIM user ID string, or NULL when not found.
   */
  protected function getScimUserId(string $email, string $idamconfig): ?string
  {
    $url = self::SECURE_LINK . $idamconfig . '/scim2/Users?filter='
      . urlencode("emails eq \"$email\"");
    $responseData = $this->apiHttpClientService->getApi($url);
    $idamUserId = $responseData['Resources'][0]['id'] ?? NULL;

    if ($idamUserId === NULL) {
      $this->logger->error('User ID not found for email: @mail', ['@mail' => $email]);
    }

    return $idamUserId;
  }

  /**
   * Verifies the current password is correct by attempting an OAuth2 token grant.
   *
   * Uses the Resource Owner Password Credentials grant type. A non-empty
   * access_token in the response confirms the old password is valid.
   *
   * @param string $email
   *   The user's email, used as the OAuth2 username.
   * @param string $oldPass
   *   The old password to validate.
   * @param string $idamconfig
   *   The IDAM hostname (without scheme).
   *
   * @return bool
   *   TRUE when the OAuth2 token endpoint returns an access_token.
   */
  protected function isOldPasswordValid(string $email, string $oldPass, string $idamconfig): bool
  {
    $payloadOld = [
      'grant_type' => 'password',
      'password' => $oldPass,
      'client_id' => 'hVBu5NSpBJHJ84KF70nfQ8ZMdnQa',
      'username' => $email,
    ];

    $resOld = $this->apiHttpClientService->postIdam(
      self::SECURE_LINK . $idamconfig . '/oauth2/token/',
      $payloadOld
    );

    return !empty($resOld['access_token']);
  }

  /**
   * Builds the SCIM PATCH payload required to update the user's password.
   *
   * Uses the SCIM enterprise schema with a single "replace" operation on the
   * "password" attribute.
   *
   * @param string $newPass
   *   The new plain-text password to set.
   *
   * @return array
   *   A SCIM-compliant PATCH body array.
   */
  protected function buildPasswordUpdatePayload(string $newPass): array
  {
    return [
      'schemas' => [
        'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User',
      ],
      'Operations' => [[
        'op' => 'replace',
        'path' => 'password',
        'value' => $newPass,
      ]],
    ];
  }

  /**
   * Derives a human-readable message from the SCIM PATCH response.
   *
   * Inspects the response in priority order:
   *  1. An error key — surfaces the detail message from the API.
   *  2. A matching email confirmation — reports success.
   *  3. A "password history" detail — explains the reuse restriction.
   *  4. Any other detail string — forwards it verbatim.
   *  5. Falls back to the default message passed by the caller.
   *
   * @param array $resPass
   *   The decoded SCIM PATCH response.
   * @param string $email
   *   The user's email, used to verify the success confirmation.
   * @param string $defaultMessage
   *   The fallback message when none of the above conditions match.
   *
   * @return string
   *   A resolved, display-ready message string.
   */
  protected function resolvePasswordChangeMessage(array $resPass, string $email, string $defaultMessage): string
  {
    $message = $defaultMessage;

    if (!empty($resPass['error'])) {
      $message = $resPass['details']['detail'] ?? 'Password update failed';
    } elseif (!empty($resPass['emails'][0]) && $resPass['emails'][0] === $email) {
      $message = 'Password changed successfully. Please log in again.';
    } elseif (!empty($resPass['detail']) && str_contains(strtolower($resPass['detail']), 'password history')) {
      $message = 'The password you are trying to use was already used in your last 3 password changes. Please choose a completely new password.';
    } elseif (!empty($resPass['detail'])) {
      $message = $resPass['detail'];
    }

    return $message;
  }

}
