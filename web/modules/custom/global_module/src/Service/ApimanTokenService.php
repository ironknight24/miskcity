<?php

namespace Drupal\global_module\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * ApimanTokenService class.
 *
 * Manages retrieval and caching of Apiman access tokens for API authentication.
 * Handles token fetching from the Apiman service, caching with expiry buffer,
 * and automatic token refresh when expired.
 */
class ApimanTokenService {

  // Cache key identifier for storing Apiman tokens
  public const CACHE_ID = 'apiman_access_token';
  
  // Buffer time (in seconds) before token expiry to trigger refresh
  // Prevents using tokens that are about to expire
  public const EXPIRY_BUFFER = 30;

  /**
   * Constructs the Apiman token service with required dependencies.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   HTTP client for making requests to Apiman service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache backend for storing and retrieving tokens.
   * @param \Drupal\global_module\Service\VaultConfigService $vaultConfigService
   *   Service for retrieving Apiman configuration from Vault.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for recording errors and debugging information.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected CacheBackendInterface $cache,
    protected VaultConfigService $vaultConfigService,
    protected LoggerInterface $logger
  ) {}

  /**
   * Get Apiman access token for API authentication.
   *
   * Retrieves a valid token from cache if available and not expired.
   * Otherwise fetches a new token from Apiman service and caches it.
   *
   * @return ?string
   *   The Apiman access token string, or NULL if token retrieval failed.
   */
  public function getApimanAccessToken(): ?string {
    // Check if a valid token exists in cache
    if ($token = $this->getCachedToken()) {
      return $token;
    }

    // Retrieve Apiman configuration from Vault
    $config = $this->getApimanConfig();
    if (!$config) {
      return NULL;
    }

    // Fetch new token from Apiman service and store in cache
    return $this->fetchAndCacheToken($config);
  }

  /* ====================================================================
   * HELPER METHODS (reduce cognitive complexity)
   *
   * Private methods organized by responsibility:
   * - Cache retrieval and validation
   * - Configuration retrieval
   * - Token fetching and caching
   * - URL and request building
   * ==================================================================== */

  /**
   * Retrieves a valid cached token if available and not expired.
   *
   * Checks cache for stored token data and validates:
   * - Token data is in array format
   * - access_token field is not empty
   * - Token has not exceeded expiry time
   *
   * @return ?string
   *   The cached access token if valid, NULL if missing or expired.
   */
  private function getCachedToken(): ?string {
    // Retrieve cached token data, default to NULL if not found
    $cached = $this->cache->get(self::CACHE_ID)->data ?? NULL;

    // Validate cached data format, token existence, and expiry time
    if (
      is_array($cached) &&
      !empty($cached['access_token']) &&
      // Check if current time is before token expiry
      time() < ($cached['expires_at'] ?? 0)
    ) {
      return $cached['access_token'];
    }

    // Token not found, invalid, or expired
    return NULL;
  }

  /**
   * Retrieves Apiman configuration from Vault service.
   *
   * Gets global variables containing API configuration including
   * API URL, version, and credentials needed for token requests.
   *
   * @return ?array
   *   Configuration array with apiUrl, apiVersion, etc., or NULL if missing.
   */
  private function getApimanConfig(): ?array {
    // Get global variables containing Vault configuration
    $globals = $this->vaultConfigService->getGlobalVariables();
    
    // Extract Apiman configuration from globals
    $config = $globals['apiManConfig']['config'] ?? NULL;

    // Log error if configuration is missing for debugging
    if (!$config) {
      $this->logger->error('Missing apiManConfig configuration in Vault response.');
    }

    return $config;
  }

  /**
   * Fetches a new access token from Apiman service and caches it.
   *
   * Makes HTTP POST request to Apiman token endpoint with configuration,
   * processes the response, and stores the token in cache with expiry time.
   *
   * @param array $config
   *   Apiman configuration array with apiUrl and apiVersion.
   *
   * @return ?string
   *   The new access token, or NULL if fetch or cache failed.
   */
  private function fetchAndCacheToken(array $config): ?string {
    try {
      // Make POST request to Apiman token endpoint
      $response = $this->httpClient->request(
        'POST',
        $this->buildTokenUrl($config),
        $this->buildRequestOptions($config)
      );

      // Decode JSON response body into associative array
      $data = json_decode($response->getBody()->getContents(), TRUE);

      // Cache token data and return the access token
      return $this->cacheToken($data);
    }
    catch (RequestException $e) {
      // Log HTTP or network errors that occur during token fetch
      $this->logger->error('Apiman token fetch failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Constructs the complete URL for the Apiman token endpoint.
   *
   * Combines API base URL, service path, version, and endpoint
   * to create the full token request URL.
   *
   * @param array $config
   *   Configuration array containing apiUrl and apiVersion.
   *
   * @return string
   *   The complete Apiman token endpoint URL.
   */
  private function buildTokenUrl(array $config): string {
    return $config['apiUrl']
      . 'tiotAPIESBSubSystem'
      . $config['apiVersion']
      . 'getAccessToken';
  }

  /**
   * Constructs HTTP request options for Apiman token request.
   *
   * Builds headers and body for the token endpoint request.
   * Disables SSL verification for internal service communication.
   *
   * @param array $config
   *   Configuration array to send as JSON request body.
   *
   * @return array
   *   Guzzle request options (headers, body, SSL settings).
   */
  private function buildRequestOptions(array $config): array {
    return [
      // Set content type to JSON for request body
      'headers' => ['Content-Type' => 'application/json'],
      // Send entire config as JSON request body
      'body' => json_encode($config),
      // Disable SSL verification for internal service
      'verify' => FALSE,
    ];
  }

  /**
   * Caches the Apiman access token with expiry time.
   *
   * Validates token data, calculates expiry timestamp with safety buffer,
   * stores in cache, and returns the access token for immediate use.
   *
   * @param ?array $data
   *   Decoded JSON response from Apiman containing access_token and expires_in.
   *
   * @return ?string
   *   The access token string, or NULL if data validation failed.
   */
  private function cacheToken(?array $data): ?string {
    // Validate that response contains required token fields
    if (
      empty($data['access_token']) ||
      empty($data['expires_in'])
    ) {
      return NULL;
    }

    // Calculate expiry timestamp: current time + token lifetime - safety buffer
    // Buffer prevents using tokens about to expire during request processing
    $expiresAt = time() + $data['expires_in'] - self::EXPIRY_BUFFER;

    // Store token in cache with expiry time matching token lifetime
    $this->cache->set(
      self::CACHE_ID,
      [
        'access_token' => $data['access_token'],
        'expires_at' => $expiresAt,
      ],
      // Cache expiry equals token lifetime (from current time)
      time() + $data['expires_in']
    );

    // Return token for immediate use
    return $data['access_token'];
  }

}
