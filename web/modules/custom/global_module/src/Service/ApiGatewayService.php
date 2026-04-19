<?php

namespace Drupal\global_module\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApiHttpClientService;
use Drupal\user\Entity\User;

/**
 * Service for managing API gateway operations.
 *
 * Handles routing requests to appropriate microservices, processing data payloads,
 * and managing user account deletion across multiple systems.
 */
class ApiGatewayService
{
    // Constant for accessing payloads in request data
    public const PAYLOADS = 'payloads';

    protected $vaultConfigService;
    protected $apiHttpClientService;

    /**
     * Constructor for dependency injection.
     *
     * @param VaultConfigService $vaultConfigService
     *   Service for retrieving configuration from vault
     * @param ApiHttpClientService $apiHttpClientService
     *   Service for making HTTP API requests
     */
    public function __construct(
        VaultConfigService $vaultConfigService,
        ApiHttpClientService $apiHttpClientService
    ) {
        $this->vaultConfigService = $vaultConfigService;
        $this->apiHttpClientService = $apiHttpClientService;
    }

    /**
     * Get the base URL for a specific service.
     *
     * Maps service names to their API endpoints and constructs the full service URL
     * using configuration from vault. Returns portal URL for 'portal' service.
     *
     * @param string $serviceName
     *   The name of the service (e.g., 'cep', 'cad', 'idam', etc.)
     *
     * @return string
     *   The complete service URL or empty string if service not found
     */
    public function getServiceUrl(string $serviceName): string
    {
        // Retrieve global configuration from vault service
        $globalVariables = $this->vaultConfigService->getGlobalVariables();

        // Extract API configuration values
        $apiUrl        = $globalVariables['apiManConfig']['config']['apiUrl'];
        $apiVer        = $globalVariables['apiManConfig']['config']['apiVersion'];
        $webportalUrl  = $globalVariables['applicationConfig']['config']['webportalUrl'];

        // Map service names to their API endpoint identifiers
        $serviceMap = [
            'cep'                => 'trinityengage-casemanagementsystem',
            'cad'                => 'trinity-respond',
            'ngcad'              => 'ngcadmobileapp',
            'iot'                => 'tiotIOTPS',
            'cityapp'            => 'tengageCity',
            'idam'               => 'UMA',
            'tiotweb'            => 'tiotweb',
            'tiotICCCOperator'   => 'tiotICCCOperator',
            'tiotcitizenapp'     => 'tiotcitizenapp',
            'innv'               => 'tiotcitizenapp',
        ];

        // Return portal URL directly if service is 'portal'
        if ($serviceName === 'portal') {
            return $webportalUrl;
        }

        // Return constructed API URL or empty string if service not found
        return isset($serviceMap[$serviceName])
            ? $apiUrl . $serviceMap[$serviceName] . $apiVer
            : '';
    }

    /**
     * Build complete service endpoint URL.
     *
     * Constructs the full endpoint URL by combining service base URL with endpoint path.
     *
     * @param array $data
     *   Data array containing 'service' and 'endPoint' keys
     *
     * @return string|null
     *   Complete endpoint URL or NULL if service URL cannot be built
     */
    private function buildServiceUrl(array $data): ?string
    {
        // Get base URL for the service
        $base = $this->getServiceUrl($data['service'] ?? '');
        // Append endpoint path if base URL exists
        return $base ? $base . ($data['endPoint'] ?? '') : NULL;
    }

    /**
     * Handle incoming POST requests and route to appropriate handlers.
     *
     * Validates POST request, extracts payload data, builds service URL,
     * and routes to appropriate handler based on request type.
     * Returns JSON response with status code and data.
     *
     * @param Request $request
     *   The HTTP request object containing POST data
     *
     * @return JsonResponse
     *   JSON response with status code and response data
     */
    public function postData(Request $request): JsonResponse
    {
        $statusCode = 200;
        $response   = [];

        try {
            // Validate that request method is POST
            if ($request->getMethod() !== 'POST') {
                throw new \LogicException('Method not allowed', 405);
            }

            // Decode JSON payload from request content
            $postData = json_decode($request->getContent(), TRUE);

            // Validate that required fields are present
            if (empty($postData['service']) || empty($postData['type'])) {
                throw new \InvalidArgumentException('Invalid payload', 400);
            }

            // Build the complete service URL
            $url      = $this->buildServiceUrl($postData);
            // Route request to appropriate handler based on type
            $response = $this->handleRequestByType($postData, $url, $request);
        } catch (\Throwable $e) {
            // Extract HTTP status code from exception or default to 500
            $statusCode = $e->getCode() ?: 500;

            // Log the error
            \Drupal::logger('post_data')->error($e->getMessage());

            // Return error response
            $response = [
                'status'  => FALSE,
                'message' => $e->getMessage() ?: 'Internal server error.',
            ];
        }

        return new JsonResponse($response, $statusCode);
    }

    /**
     * Route request to appropriate handler based on request type.
     *
     * Uses a handler map to determine how to process the request.
     * Supports special handlers for specific types (e.g., 'delyUser' for user deletion).
     * Defaults to standard API posting if no specific handler is found.
     *
     * @param array $data
     *   Request data containing 'type' and 'payloads' keys
     * @param string $url
     *   The endpoint URL to send the request to
     * @param Request $request
     *   The HTTP request object for session access
     *
     * @return array
     *   Response array from the handler
     */
    public function handleRequestByType(
        array $data,
        string $url,
        Request $request,
    ): array {
        // Get the session from the request
        $session   = $request->getSession();
        // Retrieve user data from session (populated during OAuth redirect)
        $userData  = $session->get('api_redirect_result') ?? [];
        // Extract request type
        $type      = $data['type'] ?? NULL;
        // Extract payload data
        $payload   = $data[self::PAYLOADS] ?? [];

        // Define handler functions for specific request types
        $handlers = [
            // Handler for type 2: post to API manager
            2 => fn() =>
            $this->apiHttpClientService->postApiman($url, $payload),

            // Handler for special 'delyUser' type: delete user account
            'delyUser' => fn() =>
            $this->userDelete(
                userID: $userData['userId'] ?? NULL,
                tenantCode: $userData['tenantCode'] ?? NULL
            ),
        ];

        // Call appropriate handler or default to standard API posting
        return isset($handlers[$type])
            ? $handlers[$type]()
            : $this->apiHttpClientService->postApiman($url, $payload);
    }

    /**
     * Delete user account from all systems.
     *
     * Orchestrates deletion of user account from City App, CEP (case management system),
     * and the Drupal account. Performs deletions in sequence and returns status.
     *
     * @param int $userID
     *   The unique user ID
     * @param string $tenantCode
     *   The tenant code for the user in the case management system
     *
     * @return array
     *   Response array with status and message indicating success or failure
     */
    public function userDelete(int $userID, string $tenantCode): array
    {
        // Retrieve global configuration
        $globals = $this->vaultConfigService->getGlobalVariables();

        // Extract API configuration
        $apiUrl = $globals['apiManConfig']['config']['apiUrl'];
        $apiVer = $globals['apiManConfig']['config']['apiVersion'];

        // Build City App deletion URL
        $cityDeleteUrl = $globals['applicationConfig']['config']['deleteAPICA'] . $userID;
        \Drupal::logger('City App Delete Url')->notice($cityDeleteUrl);

        // Attempt to delete from City App
        if (!$this->deleteFromCityApp($cityDeleteUrl)) {
            return [
                'status'  => FALSE,
                'message' => 'Failed to delete user account.',
            ];
        }

        // Attempt to delete from CEP (case management system)
        if (!$this->deleteFromCEP($apiUrl, $apiVer, $userID, $tenantCode)) {
            return [
                'status'  => FALSE,
                'message' => 'Failed to delete user from case management system.',
            ];
        }

        // Delete the Drupal user account
        $this->deleteDrupalAccount();

        // Return success response
        return [
            'status'  => TRUE,
            'message' => 'User account deleted successfully!',
        ];
    }

    /**
     * Delete user from City App system.
     *
     * Makes API request to City App deletion endpoint and checks response status.
     *
     * @param string $url
     *   The City App deletion API URL
     *
     * @return bool
     *   TRUE if deletion was successful, FALSE otherwise
     */
    private function deleteFromCityApp(string $url): bool
    {
        // Make API request to City App
        $response = $this->apiHttpClientService->postApi($url);
        // Log the response for debugging
        \Drupal::logger('Post Data response')->notice(print_r($response, TRUE));

        // Return success if response status is not empty
        return !empty($response['status']);
    }

    /**
     * Delete user from CEP (case management system).
     *
     * Constructs CEP deletion URL with user ID and tenant code,
     * makes API request, and checks response.
     *
     * @param string $apiUrl
     *   Base API URL
     * @param string $apiVer
     *   API version string
     * @param int $userID
     *   The unique user ID
     * @param string $tenantCode
     *   The tenant code for the user
     *
     * @return bool
     *   TRUE if deletion was successful, FALSE otherwise
     */
    private function deleteFromCEP(
        string $apiUrl,
        string $apiVer,
        int $userID,
        string $tenantCode
    ): bool {
        // Build complete CEP deletion URL with user ID and tenant code parameters
        $url = sprintf(
            '%strinityengage-casemanagementsystem%suser/delete-user?userId=%d&tenantCode=%s',
            $apiUrl,
            $apiVer,
            $userID,
            $tenantCode
        );

        // Make API request to CEP deletion endpoint
        $response = $this->apiHttpClientService->postApiman($url);
        // Log the response for debugging
        \Drupal::logger('CEP Delete API response')->notice(print_r($response, TRUE));

        // Return success if response status is not empty
        return !empty($response['status']);
    }

    /**
     * Delete the current user's Drupal account.
     *
     * Loads the current user entity and deletes it from the Drupal database.
     * This is the final step in the user deletion process.
     */
    private function deleteDrupalAccount(): void
    {
        // Load the current user entity
        $account = User::load(\Drupal::currentUser()->id());
        // Delete the user if account exists
        if ($account) {
            $account->delete();
        }
    }
}
