<?php

namespace Drupal\global_module\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Subscribes to kernel request event to call an API once per session.
 *
 * This event subscriber intercepts HTTP requests and executes API calls
 * once per user session. It handles API data processing, encryption/decryption,
 * session storage, and conditional redirects based on API response.
 *
 * Only processes requests from authenticated users on non-admin pages
 * and skips AJAX requests to minimize performance impact.
 */
class ApiRedirectSubscriber implements EventSubscriberInterface
{

  /**
   * Registers this subscriber to listen to kernel request events.
   *
   * Returns an array of events to subscribe to with their handler methods
   * and priority. Higher priority (30) ensures this runs early in the
   * request lifecycle.
   *
   * @return array
   *   Associative array with event names as keys and handler callbacks as values.
   */
  public static function getSubscribedEvents(): array
  {
    return [
      // Subscribe to REQUEST event with priority 30 (executes early in request cycle)
      KernelEvents::REQUEST => ['onKernelRequest', 30],
    ];
  }

  /**
   * Main kernel request event handler.
   *
   * This method is triggered on every HTTP request. It checks if the request
   * should be processed, retrieves cached API results from session, calls the
   * API if needed, processes the response, and handles redirects.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The kernel request event containing request and response objects.
   */
  public function onKernelRequest(RequestEvent $event): void
  {
    // Check if this request should be processed based on user, page type, etc.
    if (!$this->shouldProcess($event)) {
      return;
    }

    // Retrieve the session object from the current request
    $session = $event->getRequest()->getSession();

    // Check if API result is already stored in session (avoid duplicate API calls)
    if ($session->has('api_redirect_result')) {
      return;
    }

    // Call the external API to fetch user details or other data
    $result = $this->callYourApi();
    
    // If API returned valid data, process and store it in session
    if ($result !== NULL) {
      $this->processApiResult($result, $session);
    }

    // Check if API response indicates a redirect is needed
    if ($this->shouldRedirect($result)) {
      $this->redirectToFront($event);
    }
  }

  /* ============================================================
   * DECISION HELPERS - Request Processing Validation
   * ============================================================ */

  /**
   * Determines if the current request should be processed.
   *
   * Applies multiple filters to avoid unnecessary API calls:
   * - Only processes authenticated users (skip anonymous users)
   * - Skips AJAX requests (XMLHttpRequest)
   * - Only processes HTML requests (skip file downloads, APIs, etc.)
   * - Skips admin pages to avoid overhead on admin operations
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The kernel request event to evaluate.
   *
   * @return bool
   *   TRUE if request should be processed, FALSE otherwise.
   */
  private function shouldProcess(RequestEvent $event): bool
  {
    $request = $event->getRequest();

    return
      // User must be logged in (authenticated)
      \Drupal::currentUser()->isAuthenticated()
      // Must not be an AJAX/XMLHttpRequest
      && !$request->isXmlHttpRequest()
      // Request format must be HTML (not JSON, XML, etc.)
      && $request->getRequestFormat() === 'html'
      // Must not be an admin page
      && !$this->isAdminPath();
  }

  /**
   * Checks if the current path is an administrative path.
   *
   * Retrieves the current request path and its alias, then checks if it
   * starts with '/admin' to determine if this is an admin page.
   *
   * @return bool
   *   TRUE if current path is admin path, FALSE otherwise.
   */
  private function isAdminPath(): bool
  {
    // Get the raw internal path (e.g., /node/123, /user/profile)
    $current_path = \Drupal::service('path.current')->getPath();
    
    // Convert internal path to URL alias (e.g., /node/123 becomes /about-us)
    $alias = \Drupal::service('path_alias.manager')->getAliasByPath($current_path);

    // Check if alias starts with /admin (indicates administrative path)
    return str_starts_with($alias, '/admin');
  }

  /**
   * Determines if a redirect should be triggered based on API result.
   *
   * Checks the API response value to decide if the user should be
   * redirected to the front page.
   *
   * @param mixed $result
   *   The result from the API call.
   *
   * @return bool
   *   TRUE if result indicates redirect is needed, FALSE otherwise.
   */
  private function shouldRedirect($result): bool
  {
    // Redirect only if API explicitly returns 'redirect_me' string
    return $result === 'redirect_me';
  }

  /* ============================================================
   * API RESULT HANDLING - Data Processing and Storage
   * ============================================================ */

  /**
   * Processes and stores the API result in the session.
   *
   * Takes the raw API response, validates its format, decrypts sensitive
   * fields (email, phone), verifies required fields exist, and stores the
   * processed data in the session for later retrieval.
   *
   * @param mixed $result
   *   The raw API response data to process.
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The user's session object for storing processed data.
   */
  private function processApiResult($result, $session): void
  {
    // Validate that API response is an array (expected data structure)
    if (!is_array($result)) {
      $this->logError('Invalid API response format.');
      return;
    }

    // Decrypt sensitive fields (emailId, mobileNumber) for security
    $processed = $this->decryptSensitiveFields($result);

    // Check if required userId field is present and not empty
    if (empty($processed['userId'])) {
      // Remove any incomplete data from session
      $session->remove('api_redirect_result');
      $this->logWarning('API response missing userId.');
      return;
    }

    // Store successfully processed data in session for future requests
    $session->set('api_redirect_result', $processed);
    $this->logInfo('API data stored for userId: @uid', ['@uid' => $processed['userId']]);
  }

  /**
   * Decrypts sensitive fields in the API response data.
   *
   * Takes sensitive fields like email addresses and phone numbers
   * and decrypts them using the global variables service if they exist
   * and are not empty.
   *
   * @param array $data
   *   The API response data containing potentially encrypted fields.
   *
   * @return array
   *   The data array with sensitive fields decrypted.
   */
  private function decryptSensitiveFields(array $data): array
  {
    // Retrieve the global service for encryption/decryption operations
    $globalService = \Drupal::service('global_module.global_variables');

    // Decrypt email field if it exists and is not empty
    if (!empty($data['emailId'])) {
      $data['emailId'] = $globalService->decrypt($data['emailId']);
    }

    // Decrypt phone number field if it exists and is not empty
    if (!empty($data['mobileNumber'])) {
      $data['mobileNumber'] = $globalService->decrypt($data['mobileNumber']);
    }

    return $data;
  }

  /* ============================================================
   * REDIRECT - Page Navigation
   * ============================================================ */

  /**
   * Redirects the user to the front page.
   *
   * Creates a redirect response to the site's front page and sets it
   * on the kernel event to override the normal response handling.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The kernel request event to modify with redirect response.
   */
  private function redirectToFront(RequestEvent $event): void
  {
    // Get the URL of the front page (homepage)
    $url = Url::fromRoute('<front>')->toString();
    
    // Create a redirect response and set it on the event
    $event->setResponse(new RedirectResponse($url));
  }

  /* ============================================================
   * API CALL - External API Communication
   * ============================================================ */

  /**
   * Calls the external API to retrieve user details.
   *
   * Makes an authenticated POST request to the API Management configured endpoint,
   * sends the current user's email as userId, and returns the API response data.
   * Handles errors gracefully and returns NULL on failure.
   *
   * @return mixed
   *   The API response data array on success, NULL on failure or exception.
   */
  private function callYourApi()
  {
    try {
      // Retrieve vault configuration service for API settings
      $globalService = \Drupal::service('global_module.vault_config_service');
      $globals = $globalService->getGlobalVariables();
      
      // Retrieve token service for API authentication
      $tokenService = \Drupal::service('global_module.apiman_token_service');

      // Make POST request to API with authorization headers and user data
      $response = \Drupal::httpClient()->post(
        // Construct full API endpoint URL
        $globals['apiManConfig']['config']['apiUrl']
          . 'tiotcitizenapp'
          . $globals['apiManConfig']['config']['apiVersion']
          . 'user/details',
        [
          // Set request headers including authorization token
          'headers' => [
            'Authorization' => 'Bearer ' . $tokenService->getApimanAccessToken(),
            'Content-Type' => 'application/json',
          ],
          // Set request body with current user's email as userId
          'json' => [
            'userId' => \Drupal::currentUser()->getEmail(),
          ],
        ]
      );

      // Decode JSON response body and extract 'data' field
      $decoded = json_decode((string) $response->getBody(), TRUE);
      return $decoded['data'] ?? NULL;
    } catch (\Exception $e) {
      // Log any errors that occur during API communication
      $this->logError('API call failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /* ============================================================
   * LOGGING HELPERS - Event Recording
   * ============================================================ */

  /**
   * Logs an informational message to the API subscriber logger.
   *
   * Used for tracking normal operations and debug information.
   *
   * @param string $message
   *   The message to log.
   * @param array $context
   *   Optional placeholders for message substitution.
   */
  private function logInfo(string $message, array $context = []): void
  {
    \Drupal::logger('api_subscriber')->info($message, $context);
  }

  /**
   * Logs a warning message to the API subscriber logger.
   *
   * Used for non-critical issues that may need attention.
   *
   * @param string $message
   *   The warning message to log.
   */
  private function logWarning(string $message): void
  {
    \Drupal::logger('api_subscriber')->warning($message);
  }

  /**
   * Logs an error message to the API subscriber logger.
   *
   * Used for critical errors that prevent normal operation.
   *
   * @param string $message
   *   The error message to log.
   * @param array $context
   *   Optional placeholders for message substitution.
   */
  private function logError(string $message, array $context = []): void
  {
    \Drupal::logger('api_subscriber')->error($message, $context);
  }
}
