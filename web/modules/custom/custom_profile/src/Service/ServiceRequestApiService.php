<?php

namespace Drupal\custom_profile\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

class ServiceRequestApiService
{
    protected ClientInterface $httpClient;
    protected LoggerChannelFactoryInterface $loggerFactory;
    protected VaultConfigService $vaultConfigService;
    protected ApimanTokenService $apimanTokenService;

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
     * Helper function to get API URL.
     */
    private function getApiUrl(string $endpoint): string
    {
        $globalVariables = $this->vaultConfigService->getGlobalVariables();
        $apiUrl = $globalVariables['apiManConfig']['config']['apiUrl'];
        $apiVersion = $globalVariables['apiManConfig']['config']['apiVersion'];
        return $apiUrl . 'trinityengage-casemanagementsystem' . $apiVersion . $endpoint;
    }

    /**
     * Helper function to prepare headers for the API request.
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
     * Executes a GET request and handles errors.
     */
    private function executeGetRequest(string $url, array $options = []): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, $options);
            $data = json_decode($response->getBody(), TRUE);
            return $data ?? [];
        } catch (\Exception $e) {
            $logger = $this->loggerFactory->get('service_request'); // Get the logger channel
            $logger->error('Exception while making GET request: @message', ['@message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Executes a POST request and handles errors.
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
            $logger = $this->loggerFactory->get('service_request'); // Get the logger channel
            $logger->error('Exception while making POST request: @message', ['@message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get service request details by grievanceId and requestTypeId.
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
     * Get service requests with pagination and optional search term.
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
