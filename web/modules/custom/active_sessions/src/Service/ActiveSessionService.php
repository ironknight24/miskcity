<?php

namespace Drupal\active_sessions\Service;

use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;

/**
 * Service for managing active user sessions via IDAM API.
 *
 * Handles fetching, terminating, and managing multiple sessions
 * for authenticated users.
 */
class ActiveSessionService
{
    // Protocol prefix for secure HTTPS connections
    public const SECURE_URL = 'https://';
    // OAuth2 Bearer token prefix for Authorization header
    public const BEARER = 'Bearer ';
    // Placeholder key for error message substitution in logging
    public const MESSAGE_PLACEHOLDER = '@message';

    protected ClientInterface $httpClient;
    protected RequestStack $requestStack;
    protected LoggerInterface $logger;
    protected GlobalVariablesService $globalVariablesService;
    protected VaultConfigService $vaultConfigService;

    /**
     * Constructor for dependency injection.
     *
     * @param ClientInterface $httpClient
     *   HTTP client for making API requests
     * @param RequestStack $requestStack
     *   Stack of HTTP requests for accessing current request data
     * @param LoggerInterface $logger
     *   Logger service for recording errors and information
     * @param GlobalVariablesService $globalVariablesService
     *   Service for accessing global application variables
     * @param VaultConfigService $vaultConfigService
     *   Service for retrieving vault configuration including IDAM settings
     */
    public function __construct(
        ClientInterface $httpClient,
        RequestStack $requestStack,
        LoggerInterface $logger,
        GlobalVariablesService $globalVariablesService,
        VaultConfigService $vaultConfigService
    ) {
        $this->httpClient = $httpClient;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
        $this->globalVariablesService = $globalVariablesService;
        $this->vaultConfigService = $vaultConfigService;
    }

    /**
     * Fetch active sessions using the access token.
     *
     * Makes a GET request to the IDAM API to retrieve all active sessions
     * for the authenticated user. Includes current request cookies in the request.
     *
     * @param string $accessToken
     *   The OAuth2 access token for authorization
     *
     * @return array|null
     *   Decoded JSON array of sessions or NULL on failure
     */
    public function fetchActiveSessions(string $accessToken): ?array
    {
        // Get the current HTTP request from the request stack
        $request = $this->requestStack->getCurrentRequest();

        // Extract cookies from the current request headers (used for session context)
        $cookies = $request->headers->get('cookie');
        
        // Retrieve IDAM configuration from vault service
        $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
        
        // Construct the IDAM API endpoint URL for retrieving user sessions
        $url = self::SECURE_URL. $idamconfig .':9443/api/users/v1/me/sessions';
        
        try {
            // Make HTTP GET request to fetch active sessions
            $response = $this->httpClient->request('GET', $url , [
                'headers' => [
                    'Accept' => '*/*',
                    'Authorization' => self::BEARER . $accessToken,
                    'Cookie' => $cookies,
                ],
                // Disable SSL verification (use with caution in production)
                'verify' => FALSE,
            ]);

            // Decode the JSON response body into an associative array
            $data = json_decode($response->getBody()->getContents(), TRUE);
            return $data ?? [];
        } catch (\Exception $e) {
            // Log the error with exception message
            $this->logger->error('Error fetching active sessions: @message', [self::MESSAGE_PLACEHOLDER => $e->getMessage()]);
            return NULL;
        }
    }

    /**
     * Terminate a specific session by its ID.
     *
     * Sends a DELETE request to the IDAM API to terminate a single session.
     * Typically used when a user wants to sign out from a specific device/browser.
     *
     * @param string $session_id
     *   The unique identifier of the session to terminate
     * @param string $access_token
     *   The OAuth2 access token for authorization
     *
     * @return bool
     *   TRUE if termination succeeded, FALSE otherwise
     */
    public function terminateSession(string $session_id, string $access_token): bool
    {
        // Retrieve IDAM configuration from vault service
        $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
        
        // Construct the IDAM API endpoint URL for the specific session
        $url = self::SECURE_URL . $idamconfig .':9443/api/users/v1/me/sessions/' . $session_id;

        try {
            // Make HTTP DELETE request to terminate the session
            $this->httpClient->request('DELETE', $url, [
                'headers' => [
                    'Accept' => '*/*',
                    'Authorization' => self::BEARER . $access_token,
                ],
                // Disable SSL verification (use with caution in production)
                'verify' => FALSE,
            ]);

            return TRUE;
        } catch (RequestException $e) {
            // Log the error if termination fails
            $this->logger->error('Failed to terminate session: @message', [self::MESSAGE_PLACEHOLDER => $e->getMessage()]);
            return FALSE;
        }
    }

    /**
     * Terminate all other active sessions except the current one.
     *
     * Sends a DELETE request to the IDAM API without specifying a session ID,
     * which terminates all other sessions while keeping the current one active.
     *
     * @param string $access_token
     *   The OAuth2 access token for authorization
     *
     * @return bool
     *   TRUE if termination succeeded, FALSE otherwise
     */
    public function terminateAllOtherSessions(string $access_token): bool
    {
        // Retrieve IDAM configuration from vault service
        $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
        
        // Construct the IDAM API endpoint URL for terminating all other sessions
        $url = self::SECURE_URL . $idamconfig .':9443/api/users/v1/me/sessions';

        try {
            // Make HTTP DELETE request to terminate all other sessions
            $this->httpClient->request('DELETE', $url, [
                'headers' => [
                    'Accept' => '*/*',
                    'Authorization' => self::BEARER . $access_token,
                ],
                // Disable SSL verification (use with caution in production)
                'verify' => FALSE,
            ]);

            return TRUE;
        } catch (RequestException $e) {
            // Log the error if termination fails
            $this->logger->error('Failed to terminate all other sessions: @message', [self::MESSAGE_PLACEHOLDER => $e->getMessage()]);
            return FALSE;
        }
    }
}
