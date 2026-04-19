<?php

namespace Drupal\reportgrievance\Service;

use GuzzleHttp\ClientInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

/**
 * GrievanceApiService class.
 *
 * Provides API integration for grievance reporting functionality.
 * Handles retrieval of incident types and subtypes, and submission
 * of grievance reports with checksum validation and CSRF protection.
 */
class GrievanceApiService
{

  // Content type constant for JSON HTTP requests
  public const APP_JSON = 'application/json';
  // Bearer token prefix for Authorization headers
  public const BEARER = 'Bearer ';
  // HTTP client for making API requests
  protected $httpClient;
  // Secret key for generating HMAC checksums
  protected $secret = 'replace_with_your_secret_key';
  // Service for global variables and decryption
  protected $globalVariablesService;
  // Service for retrieving Vault configuration
  protected $vaultConfigService;
  // Service for retrieving Apiman access tokens
  protected $apimanTokenService;

  /**
   * Constructs the GrievanceApiService with required dependencies.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   HTTP client for making API requests.
   * @param \Drupal\global_module\Service\GlobalVariablesService $global_variables_service
   *   Service for global variables and decryption.
   * @param \Drupal\global_module\Service\VaultConfigService $vault_config_service
   *   Service for retrieving Vault configuration.
   * @param \Drupal\global_module\Service\ApimanTokenService $apiman_token_service
   *   Service for retrieving Apiman access tokens.
   */
  public function __construct(ClientInterface $http_client, GlobalVariablesService $global_variables_service, VaultConfigService $vault_config_service, ApimanTokenService $apiman_token_service)
  {
    // Store HTTP client reference
    $this->httpClient = $http_client;
    // Store global variables service reference
    $this->globalVariablesService = $global_variables_service;
    // Store Vault config service reference
    $this->vaultConfigService = $vault_config_service;
    // Store Apiman token service reference
    $this->apimanTokenService = $apiman_token_service;
  }

  /**
   * Generates HMAC-SHA256 checksum for data integrity validation.
   *
   * Sorts data keys, encodes as JSON, and computes HMAC using secret key.
   * Used to ensure data integrity during grievance submission.
   *
   * @param array $data
   *   The data array to generate checksum for.
   *
   * @return string
   *   The HMAC-SHA256 checksum as hexadecimal string.
   */
  protected function generateChecksum(array $data): string
  {
    // Sort array keys to ensure consistent ordering
    ksort($data);
    // Encode data as JSON with unescaped slashes and Unicode
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Generate HMAC-SHA256 checksum using secret key
    return hash_hmac('sha256', $json, $this->secret);
  }

  /**
   * Retrieves CSRF token for form submission protection.
   *
   * Generates a CSRF token using Drupal's CSRF token service.
   *
   * @return string
   *   The CSRF token string.
   */
  protected function getCsrfToken(): string
  {
    // Generate CSRF token for empty string (global token)
    return \Drupal::service('csrf_token')->get('');
  }

  /**
   * Retrieves incident types from the API.
   *
   * Makes GET request to fetch available incident types for grievance reporting.
   * Returns formatted options array suitable for form select elements.
   *
   * @return array
   *   Array of incident type options with ID as key and name as value.
   */
  public function getIncidentTypes()
  {
    // Retrieve global configuration from Vault
    $globalVariables = $this->vaultConfigService->getGlobalVariables();
    // Get Apiman access token for API authentication
    $accessToken = $this->apimanTokenService->getApimanAccessToken();

    // Construct API endpoint URL for incident types
    $incidentTypeUrl = $globalVariables['apiManConfig']['config']['apiUrl'] . 'trinityengage-casemanagementsystem' . $globalVariables['apiManConfig']['config']['apiVersion'] . 'master-data/incident-types';

    // Make GET request to retrieve incident types
    $response = $this->httpClient->request('GET', $incidentTypeUrl, [
      // Add tenant code as query parameter
      'query' => [
        'tenantCode' => $globalVariables['applicationConfig']['config']['ceptenantCode'],
      ],
      // Set request headers for JSON response and authentication
      'headers' => [
        'Accept' => self::APP_JSON,
        'Authorization' => self::BEARER . $accessToken,
      ],
      // Disable SSL verification for internal API
      'verify' => FALSE,
    ]);

    // Decode JSON response body
    $data = json_decode($response->getBody(), TRUE);
    // Initialize options array for form select
    $options = [];
    // Process API response data into options array
    if (!empty($data['data'])) {
      foreach ($data['data'] as $type) {
        // Use incident type ID as key and name as value
        $options[$type['incidentTypeId']] = $type['incidentType'];
      }
    }

    return $options;
  }

  /**
   * Retrieves incident subtypes for a given incident type.
   *
   * Makes GET request to fetch subtypes filtered by incident type ID.
   * Returns formatted options array for dependent form select elements.
   *
   * @param int $incidentTypeId
   *   The incident type ID to filter subtypes.
   *
   * @return array
   *   Array of incident subtype options with ID as key and name as value.
   */
  public function getIncidentSubTypes(int $incidentTypeId): array
  {
    // Retrieve global configuration from Vault
    $globalVariables = $this->vaultConfigService->getGlobalVariables();
    // Get Apiman access token for API authentication
    $accessToken = $this->apimanTokenService->getApimanAccessToken();

    // Check if access token was successfully retrieved
    if (!$accessToken) {
      // Log error if token retrieval failed
      \Drupal::logger('report_grievance')->error('Apiman access token could not be retrieved.');
      return [];
    }

    // Construct API endpoint URL for incident subtypes
    $incidentSubTypeUrl = $globalVariables['apiManConfig']['config']['apiUrl'] . 'trinityengage-casemanagementsystem' . $globalVariables['apiManConfig']['config']['apiVersion'] . 'master-data/incident-sub-types';

    // Make GET request to retrieve incident subtypes
    $response = $this->httpClient->request('GET', $incidentSubTypeUrl, [
      // Add tenant code and incident type ID as query parameters
      'query' => [
        'tenantCode' => $globalVariables['applicationConfig']['config']['ceptenantCode'],
        'incidentTypeId' => $incidentTypeId,
      ],
      // Set request headers for JSON response and authentication
      'headers' => [
        'Accept' => self::APP_JSON,
        'Authorization' => self::BEARER . $accessToken,
      ],
      // Disable SSL verification for internal API
      'verify' => FALSE,
    ]);

    // Decode JSON response body
    $data = json_decode($response->getBody(), TRUE);
    // Initialize options array for form select
    $options = [];
    // Process API response data into options array
    if (!empty($data['data'])) {
      foreach ($data['data'] as $subType) {
        // Use incident subtype ID as key and name as value
        $options[$subType['incidentSubTypeId']] = $subType['incidentSubType'];
      }
    }

    return $options;
  }

  /**
   * Submits a grievance report to the API.
   *
   * Generates checksum and CSRF token for security, then makes POST request
   * to submit grievance data. Handles exceptions and returns success/failure status.
   *
   * @param array $data
   *   The grievance data to submit.
   *
   * @return array
   *   Array with 'success' boolean and optional 'data' from API response.
   */
  public function sendGrievance(array $data): array
  {
    // Generate HMAC checksum for data integrity
    $checksum = $this->generateChecksum($data);
    // Get CSRF token for form submission protection
    $csrf_token = $this->getCsrfToken();

    // Retrieve global configuration from Vault
    $globalVariables = $this->vaultConfigService->getGlobalVariables();
    // Get Apiman access token for API authentication
    $accessToken = $this->apimanTokenService->getApimanAccessToken();

    // Construct API endpoint URL for grievance submission
    $grivanceUrl = $globalVariables['apiManConfig']['config']['apiUrl'] . 'trinityengage-casemanagementsystem' . $globalVariables['apiManConfig']['config']['apiVersion'] . 'grievance-manage/report-grievance';

    try {
      // Make POST request to submit grievance
      $response = $this->httpClient->request('POST', $grivanceUrl, [
        // Set request headers including security tokens
        'headers' => [
          'Content-Type' => self::APP_JSON,
          'Authorization' => self::BEARER . $accessToken,
          'X-CSRF-Token' => $csrf_token,
          'X-Checksum' => $checksum,
        ],
        // Send data as JSON payload
        'json' => $data,
        // Set request timeout
        'timeout' => 10,
      ]);

      // Decode JSON response body
      $body = json_decode($response->getBody()->getContents(), TRUE);
      // Return success response with API data
      return ['success' => TRUE, 'data' => $body];
    } catch (\Exception $e) {
      // Log API submission error
      \Drupal::logger('report_grievance')->error('API submission failed: @message', ['@message' => $e->getMessage()]);
      // Return failure response
      return ['success' => FALSE];
    }
  }
}
