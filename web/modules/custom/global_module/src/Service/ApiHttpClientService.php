<?php

namespace Drupal\global_module\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * ApiHttpClientService class.
 *
 * Provides HTTP client wrapper methods for making API requests to various endpoints.
 * Abstracts away low-level HTTP details and provides convenient methods for different
 * authentication methods (Apiman, IDAM, Basic auth). Centralizes error handling and logging.
 */
class ApiHttpClientService
{

    // Content type constant for JSON responses
    public const APP_JSON = 'application/json';
    
    // Bearer token prefix for Authorization headers
    public const BEARER = 'Bearer ';

    /**
     * Constructs the HTTP client service with required dependencies.
     *
     * @param \GuzzleHttp\ClientInterface $httpClient
     *   The HTTP client for making requests.
     * @param \Psr\Log\LoggerInterface $logger
     *   The logger for recording errors and exceptions.
     * @param \Drupal\global_module\Service\ApimanTokenService $apimanTokenService
     *   The service for retrieving Apiman access tokens.
     */
    public function __construct(
        protected ClientInterface $httpClient,
        protected LoggerInterface $logger,
        protected ApimanTokenService $apimanTokenService
    ) {}

    /* ------------------------------------------------------------------------
     * PUBLIC WRAPPERS (Very low cognitive complexity)
     *
     * These methods provide convenient, high-level interfaces for making
     * API requests with pre-configured headers and authentication based on
     * the target API gateway/service.
     * --------------------------------------------------------------------- */

    /**
     * Makes a request to Apiman-protected API endpoints.
     *
     * Automatically includes Apiman authorization token and JSON headers.
     * Used for communicating with APIs protected by the Apiman API gateway.
     *
     * @param string $url
     *   The full API endpoint URL.
     * @param array $payload
     *   Optional JSON payload to send in the request body.
     * @param string $method
     *   HTTP method (default: POST). Can be POST, PUT, PATCH, etc.
     *
     * @return array
     *   Decoded JSON response from the API or error array.
     */
    public function postApiman(string $url, array $payload = [], string $method = 'POST'): array
    {
        return $this->request($method, $url, [
            'headers' => $this->apimanHeaders(),
            'json' => $payload,
        ]);
    }

    /**
     * Sends a DELETE request to Apiman-protected API endpoints.
     *
     * Includes Apiman authorization and JSON headers for DELETE operations.
     *
     * @param string $url
     *   The full API endpoint URL to delete from.
     *
     * @return array
     *   Decoded JSON response from the API or error array.
     */
    public function deleteApiman(string $url): array
    {
        return $this->request('DELETE', $url, [
            'headers' => $this->apimanHeaders(),
        ]);
    }

    /**
     * Makes a request to IDAM endpoints using form-encoded data.
     *
     * Used for IDAM (Identity and Access Management) endpoints that expect
     * form-encoded parameters instead of JSON. Disables SSL verification.
     *
     * @param string $url
     *   The IDAM endpoint URL.
     * @param array $payload
     *   Form parameters to send.
     * @param string $method
     *   HTTP method (default: POST).
     *
     * @return array
     *   Decoded JSON response from IDAM or error array.
     */
    public function postIdam(string $url, array $payload = [], string $method = 'POST'): array
    {
        return $this->request($method, $url, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => $payload,
            'verify' => false,
        ]);
    }

    /**
     * Makes an authenticated request to IDAM using Basic authentication.
     *
     * Used for IDAM endpoints requiring Basic auth with hardcoded credentials.
     * Disables SSL verification for internal IDAM endpoints.
     *
     * @param string $url
     *   The IDAM endpoint URL.
     * @param array $payload
     *   JSON payload to send in request body.
     * @param string $method
     *   HTTP method (default: POST).
     *
     * @return array
     *   Decoded JSON response from IDAM or error array.
     */
    public function postIdamAuth(string $url, array $payload = [], string $method = 'POST'): array
    {
        return $this->request($method, $url, [
            'headers' => [
                'Accept' => self::APP_JSON,
                'Authorization' => 'Basic ' . base64_encode('trinity:trinity@123'),
            ],
            'json' => $payload,
            'verify' => false,
        ], 'idam_auth');
    }

    /**
     * Makes a POST request to generic API endpoints.
     *
     * Used for standard APIs without specific authentication requirements.
     * Disables SSL verification for flexibility.
     *
     * @param string $url
     *   The API endpoint URL.
     *
     * @return ?array
     *   Decoded JSON response or NULL on error.
     */
    public function postApi(string $url): ?array
    {
        return $this->request('POST', $url, [
            'headers' => ['Accept' => self::APP_JSON],
            'verify' => false,
        ]);
    }

    /**
     * Makes a GET request to generic API endpoints with Basic authentication.
     *
     * Used for retrieving data from standard APIs that require Basic auth.
     * Disables SSL verification for flexibility.
     *
     * @param string $url
     *   The API endpoint URL to retrieve from.
     *
     * @return ?array
     *   Decoded JSON response or NULL on error.
     */
    public function getApi(string $url): ?array
    {
        return $this->request('GET', $url, [
            'headers' => [
                'Accept' => self::APP_JSON,
                'Authorization' => 'Basic ' . base64_encode('trinity:trinity@123'),
            ],
            'verify' => false,
        ]);
    }

    /* ------------------------------------------------------------------------
     * CORE REQUEST HANDLER (ONLY place with try/catch)
     *
     * Centralizes all HTTP request execution and error handling to maintain
     * consistency and reduce code duplication across public wrapper methods.
     * --------------------------------------------------------------------- */

    /**
     * Executes an HTTP request and returns the decoded JSON response.
     *
     * Handles all HTTP-level details including error catching, exception logging,
     * and JSON decoding. This is the only place in the service where try/catch
     * blocks are used.
     *
     * @param string $method
     *   HTTP method (GET, POST, PUT, DELETE, etc.).
     * @param string $url
     *   The full URL to send the request to.
     * @param array $options
     *   Guzzle request options (headers, json, form_params, etc.).
     *
     * @return ?array
     *   Decoded JSON response array on success, error array or NULL on failure.
     */
    private function request(
        string $method,
        string $url,
        array $options = []
    ): ?array {
        try {
            // Execute the HTTP request using Guzzle client
            $response = $this->httpClient->request(
                strtoupper($method),
                $url,
                $options
            );

            // Decode response body as JSON and return as associative array
            return json_decode(
                $response->getBody()->getContents(),
                true
            );
        } catch (RequestException $e) {
            // Handle Guzzle request exceptions (network errors, HTTP errors, etc.)
            $this->logException($e);
            return ['error' => 'Request failed'];
        } catch (\Exception $e) {
            // Handle any other unexpected exceptions
            $this->logger->error(
                'HTTP request failed: @message',
                ['@message' => $e->getMessage()]
            );
            return null;
        }
    }

    /* ------------------------------------------------------------------------
     * HELPERS
     *
     * Private utility methods for common tasks like building headers and
     * formatting error messages for logging.
     * --------------------------------------------------------------------- */

    /**
     * Constructs headers required for Apiman API requests.
     *
     * Includes JSON content type, Accept header, and Bearer token authorization.
     *
     * @return array
     *   Associative array of HTTP headers for Apiman requests.
     */
    private function apimanHeaders(): array
    {
        return [
            'Content-Type' => self::APP_JSON,
            'Accept' => self::APP_JSON,
            'Authorization' => self::BEARER . $this->apimanTokenService->getApimanAccessToken(),
        ];
    }

    /**
     * Logs detailed information about a request exception.
     *
     * Extracts response body from the exception if available and includes
     * it in the log message for debugging purposes.
     *
     * @param \GuzzleHttp\Exception\RequestException $e
     *   The request exception to log.
     */
    private function logException(RequestException $e): void
    {
        // Extract response body if HTTP response exists, NULL otherwise
        $responseBody = $e->hasResponse()
            ? (string) $e->getResponse()->getBody()
            : null;

        // Log error with both exception message and response body
        $this->logger->error(
            'HTTP request failed: @message | Response: @response',
            [
                '@message' => $e->getMessage(),
                '@response' => $responseBody,
            ]
        );
    }
}
