<?php

namespace Drupal\custom_profile\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

/**
 * API client for the Trinity Engage case-management service-request endpoints.
 *
 * Provides two public methods:
 *  - getServiceRequests() — paginated list of a user's service requests.
 *  - getServiceRequestDetails() — full record for one request by grievance ID.
 *
 * Both methods delegate HTTP execution to private helpers that centralise
 * header construction, URL assembly, and error logging, keeping the public
 * API surface simple.
 */
class ServiceRequestApiService
{

  /**
   * The HTTP client used for all outbound API calls.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Logger channel factory; the "service_request" channel is used for errors.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Provides Vault-stored configuration including the API base URL and version.
   *
   * @var \Drupal\global_module\Service\VaultConfigService
   */
  protected VaultConfigService $vaultConfigService;

  /**
   * Provides a short-lived bearer token for API Manager authentication.
   *
   * @var \Drupal\global_module\Service\ApimanTokenService
   */
  protected ApimanTokenService $apimanTokenService;

  /**
   * Constructs a ServiceRequestApiService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   * @param \Drupal\global_module\Service\VaultConfigService $vault_config_service
   *   The Vault configuration service.
   * @param \Drupal\global_module\Service\ApimanTokenService $apiman_token_service
   *   The API Manager token service.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $loggerFactory,
    VaultConfigService $vault_config_service,
    ApimanTokenService $apiman_token_service
  ) {
    $this->httpClient = $http_client;
    $this->loggerFactory = $loggerFactory;
    $this->vaultConfigService = $vault_config_service;
    $this->apimanTokenService = $apiman_token_service;
  }

  /**
   * Builds the full URL for a given case-management API endpoint.
   *
   * Combines the configured API base URL, the fixed service path segment
   * "trinityengage-casemanagementsystem", the API version, and the supplied
   * endpoint fragment.
   *
   * @param string $endpoint
   *   The endpoint path fragment (e.g. "common/service-request").
   *
   * @return string
   *   The fully-qualified URL ready to pass to the HTTP client.
   */
  private function getApiUrl(string $endpoint): string
  {
    $globalVariables = $this->vaultConfigService->getGlobalVariables();
    $apiUrl = $globalVariables['apiManConfig']['config']['apiUrl'];
    $apiVersion = $globalVariables['apiManConfig']['config']['apiVersion'];
    return $apiUrl . 'trinityengage-casemanagementsystem' . $apiVersion . $endpoint;
  }

  /**
   * Builds the standard Authorization and content-type headers for API calls.
   *
   * A fresh bearer token is fetched on every call rather than cached, so
   * token expiry is handled transparently.
   *
   * @return array
   *   An associative array of HTTP header name => value pairs.
   */
  private function getHeaders(): array
  {
    $accessToken = $this->apimanTokenService->getApimanAccessToken();
    return [
      'Authorization' => 'Bearer ' . $accessToken,
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];
  }

  /**
   * Executes a GET request and returns the decoded JSON body.
   *
   * Any exception is caught, logged to the "service_request" channel, and an
   * empty array is returned so callers do not need to handle HTTP exceptions.
   *
   * @param string $url
   *   The full request URL.
   * @param array $options
   *   Additional Guzzle request options (e.g. headers).
   *
   * @return array
   *   The decoded response body, or an empty array on failure or null response.
   */
  private function executeGetRequest(string $url, array $options = []): array
  {
    try {
      $response = $this->httpClient->request('GET', $url, $options);
      $data = json_decode($response->getBody(), TRUE);
      return $data ?? [];
    } catch (\Exception $e) {
      $logger = $this->loggerFactory->get('service_request');
      $logger->error('Exception while making GET request: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Executes a POST request with a JSON body and returns the decoded response.
   *
   * Headers are assembled internally via getHeaders(). Any exception is caught,
   * logged, and an empty array is returned to the caller.
   *
   * @param string $url
   *   The full request URL.
   * @param array $body
   *   The payload to send as JSON.
   *
   * @return array
   *   The decoded response body, or an empty array on failure or null response.
   */
  private function executePostRequest(string $url, array $body): array
  {
    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $this->getHeaders(),
        'json' => $body,
      ]);
      $data = json_decode($response->getBody(), TRUE);
      return $data ?? [];
    } catch (\Exception $e) {
      $logger = $this->loggerFactory->get('service_request');
      $logger->error('Exception while making POST request: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Retrieves the full details of a single service request by grievance ID.
   *
   * @param string $grievanceId
   *   The grievance reference number (e.g. "GV-123").
   * @param int $requestTypeId
   *   The numeric request-type discriminator expected by the API.
   *
   * @return array
   *   The decoded API response, or an empty array on failure.
   */
  public function getServiceRequestDetails(string $grievanceId, int $requestTypeId): array
  {
    $url = $this->getApiUrl('common/service-request-by-grievance?grievanceId=' . $grievanceId . '&requestTypeId=' . $requestTypeId);
    $options = [
      'headers' => $this->getHeaders(),
    ];
    return $this->executeGetRequest($url, $options);
  }

  /**
   * Retrieves a paginated list of service requests for a given user.
   *
   * The API always sorts by the default order (orderBy/orderByfield = 1) and
   * filters to requestTypeId = 1. An optional search term is forwarded
   * verbatim to the API.
   *
   * @param int $userId
   *   The citizen platform user ID.
   * @param int $page
   *   The 1-based page number. Defaults to 1.
   * @param int $itemsPerPage
   *   The number of records per page. Defaults to 10.
   * @param string $searchTerm
   *   An optional free-text search term. Defaults to an empty string.
   *
   * @return array
   *   The decoded API response containing "data" and pagination metadata,
   *   or an empty array on failure.
   */
  public function getServiceRequests(int $userId, int $page = 1, int $itemsPerPage = 10, string $searchTerm = ''): array
  {
    $globalVariables = $this->vaultConfigService->getGlobalVariables();
    $url = $this->getApiUrl('common/service-request');
    $body = [
      'tenantCode' => $globalVariables['applicationConfig']['config']['tenantCode'] ?? '',
      'search' => $searchTerm,
      'pageNumber' => $page,
      'itemsPerPage' => $itemsPerPage,
      'userId' => $userId,
      'requestTypeId' => 1,
      'orderBy' => '1',
      'orderByfield' => '1',
    ];
    return $this->executePostRequest($url, $body);
  }

}
