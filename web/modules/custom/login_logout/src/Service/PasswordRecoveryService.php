<?php

namespace Drupal\login_logout\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;

/**
 * Service for handling password recovery related API calls.
 */
class PasswordRecoveryService
{
  public const APP_JSON = 'application/json';
  public const FORM_URLENCODED = 'application/x-www-form-urlencoded';
  public const SECURE_LINK = 'https://';
  public const EMAIL_PLACEHOLDER = '@email';
  /**
   * HTTP client service.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Global variables service.
   *
   * @var \Drupal\global_module\Service\GlobalVariablesService
   */
  protected $globalVariablesService;
  protected $vaultConfigService;

  /**
   * Constructs a PasswordRecoveryService object.
   */
  public function __construct(
    ClientInterface $http_client,
    VaultConfigService $vaultConfigService,
    LoggerChannelInterface $logger
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->vaultConfigService = $vaultConfigService;
  }

  /**
   * Fetches the SCIM username for a given email.
   *
   * @param string $email
   *   The user's email.
   *
   * @return string|null
   *   The SCIM userName, or NULL if not found.
   */
  public function get_scim_username_by_email(string $email): ?string
  {
    try {
      // Build the SCIM filter.
      $filter = sprintf("emails eq %s", $email);
      $attributes = "username";

      $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
      $url = self::SECURE_LINK . $idamconfig . '/scim2/Users/?filter=' . rawurlencode($filter)
        . '&attributes=' . $attributes;

      // Prepare headers.
      $headers = [
        'Authorization' => 'Basic dHJpbml0eTp0cmluaXR5QDEyMw==',
        'Cookie' => 'route=1764759023.643.27377.401456|4df79418c1cd0ad4939e0cb01c3293ae',
      ];

      // Call the API.
      $response = $this->httpClient->request('GET', $url, [
        'headers' => $headers,
        'http_errors' => FALSE,
        'timeout' => 10,
        'verify' => FALSE,
      ]);

      // Decode JSON.
      $data = json_decode($response->getBody()->getContents(), TRUE);

      // Check and extract the userName.
      if (!empty($data['Resources'][0]['userName'])) {
        return $data['Resources'][0]['userName'];
      }

      return NULL;
    } catch (\Exception $e) {
      // Log and return NULL if needed.
      $this->logger->error('SCIM username fetch failed for email @email: @msg', [
        self::EMAIL_PLACEHOLDER => $email,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Initiate password recovery process.
   *
   * @param string $email
   *   The email address of the user.
   *
   * @return string|null
   *   The recovery code or NULL on failure.
   */
  public function initiateRecovery(string $email): ?string
  {
    $username = $this->get_scim_username_by_email($email);
    $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
    $url = self::SECURE_LINK . $idamconfig . '/api/users/v2/recovery/password/init';

    $payload = [
      'claims' => [
        [
          'uri' => 'http://wso2.org/claims/username',
          'value' => 'primary/' . $username,
        ],
      ],
      'properties' => [
        [
          'key' => 'key',
          'value' => 'value',
        ],
      ],
    ];

    $headers = [
      'accept' => self::APP_JSON,
      'Content-Type' => self::APP_JSON,
      'Authorization' => 'Basic YWRtaW46VHJpbml0eUAxMjM=',
    ];

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $headers,
        'json' => $payload,
        'timeout' => 10,
        'verify' => FALSE,
      ]);
      $decoded = json_decode($response->getBody()->getContents(), TRUE);

      if (!empty($decoded[0]['channelInfo']['recoveryCode'])) {
        return $decoded[0]['channelInfo']['recoveryCode'];
      }

      $this->logger->warning('No recovery code returned for email: @email', [self::EMAIL_PLACEHOLDER => $email]);
      
      return NULL;
    } catch (RequestException $e) {
      $this->logger->error('Password recovery initiation failed: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Complete password recovery using recovery code.
   *
   * @param string $recovery_code
   *   The recovery code received in the email or SMS.
   * @param string $channel_id
   *   The channel ID (usually "1" or "2" depending on medium).
   *
   * @return array|null
   *   The decoded JSON response or NULL on failure.
   */
  public function completeRecovery(string $recovery_code, string $channel_id = '1'): ?array
  {

    $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
    $url = self::SECURE_LINK . $idamconfig . '/api/users/v2/recovery/password/recover';

    $payload = [
      'recoveryCode' => $recovery_code,
      'channelId' => $channel_id,
      'properties' => [
        [
          'key' => 'key',
          'value' => 'value',
        ],
      ],
    ];

    $headers = [
      'accept' => self::APP_JSON,
      'Content-Type' => self::APP_JSON,
      'Authorization' => 'Basic dHJpbml0eTp0cmluaXR5QDEyMw==',
    ];

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $headers,
        'json' => $payload,
        'verify' => FALSE,
      ]);

      $decoded = json_decode($response->getBody()->getContents(), TRUE);
      $this->logger->info('Password recovery completed successfully for recovery code: @code', ['@code' => $recovery_code]);
      return $decoded;
    } catch (RequestException $e) {
      $this->logger->error('Password recovery completion failed: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Combined method: Initiate and complete password recovery.
   *
   * @param string $email
   *   The user's email.
   *
   * @return array|null
   *   The final response from the completeRecovery() call.
   */
  public function recoverPassword(string $email): ?array
  {
    try {
      // Step 1: Initiate recovery to get the recovery code.
      $recovery_code = $this->initiateRecovery($email);
      if (empty($recovery_code)) {
        $this->logger->warning('Password recovery initiation failed for email: @email', [self::EMAIL_PLACEHOLDER => $email]);
        return NULL;
      }

      // Step 2: Complete recovery using the code.
      return $this->completeRecovery($recovery_code, '1');

    } catch (\Exception $e) {
      $this->logger->error('Password recovery process failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }
}
