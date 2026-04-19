<?php

namespace Drupal\global_module\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApiHttpClientService;

/**
 * GlobalVariablesService class.
 *
 * Provides core functionality for file uploads, encryption/decryption,
 * user profile updates, and session data management. Acts as a service layer
 * for global module operations including API communication and file handling.
 */
class GlobalVariablesService
{

  // Logger instance for recording errors and debug information
  protected $logger;

  // Cache backend for storing application data
  protected $cache;

  // JSON content type constant for HTTP requests
  public const APP_JSON = 'application/json';

  // Bearer token prefix for Authorization headers
  public const BEARER = 'Bearer ';

  // String constant for status field in responses
  public const STR_STS = 'status';

  // String constant for payload field
  const PAYLOADS = 'payload';

  /**
   * HTTP client for making external requests.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  // Service for retrieving Apiman access tokens
  protected $apimanTokenService;

  // Service for retrieving Vault configuration
  protected $vaultConfigService;

  // Service for making HTTP requests to APIs
  protected $apiHttpClientService;

  /**
   * Constructs a new GlobalVariablesService.
   *
   * Initializes the service with required dependencies for logging,
   * caching, HTTP communication, and API authentication.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   HTTP client for making external requests.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Factory for creating logger instances.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache backend for storing data.
   * @param \Drupal\global_module\Service\ApimanTokenService $apimanTokenService
   *   Service for Apiman token management.
   * @param \Drupal\global_module\Service\VaultConfigService $vaultConfigService
   *   Service for Vault configuration retrieval.
   * @param \Drupal\global_module\Service\ApiHttpClientService $apiHttpClientService
   *   Service for API HTTP communication.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    CacheBackendInterface $cache,
    ApimanTokenService $apimanTokenService,
    VaultConfigService $vaultConfigService,
    ApiHttpClientService $apiHttpClientService
  )
  {
    // Initialize logger channel for this service
    $this->logger = $logger_factory->get('global_variables_service');
    // Store cache backend reference
    $this->cache = $cache;
    // Store HTTP client reference
    $this->httpClient = $http_client;
    // Store Apiman token service reference
    $this->apimanTokenService = $apimanTokenService;
    // Store Vault config service reference
    $this->vaultConfigService = $vaultConfigService;
    // Store API HTTP client service reference
    $this->apiHttpClientService = $apiHttpClientService;
  }

  /**
   * Decrypts encrypted values using AES-128-ECB cipher.
   *
   * Decodes base64-encoded ciphertext and decrypts using OpenSSL with
   * a hardcoded encryption key. Removes PKCS#7 padding from decrypted output.
   *
   * @param string $value
   *   Base64-encoded encrypted string.
   *
   * @return ?string
   *   Decrypted plaintext string, or NULL if decryption fails.
   */
  public function decrypt($value)
  {
    // Encryption key used for AES decryption (hardcoded for consistency)
    $key = "Fl%JTt%d954n@PoU";

    // Cipher algorithm: AES-128 with ECB mode
    $cipher = "AES-128-ECB";

    try {
      // Decode base64 encoded ciphertext to binary
      $ciphertext = base64_decode($value);

      // Decrypt using OpenSSL with raw data and zero padding
      $decrypted = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

      // If decryption succeeded, remove PKCS#7 padding
      if ($decrypted !== FALSE) {
        // Get padding length from last byte
        $pad = ord($decrypted[strlen($decrypted) - 1]);

        // Remove padding bytes from end of string
        $decrypted = substr($decrypted, 0, -$pad);
      }

      return $decrypted;
    } catch (\Exception $e) {
      // Log decryption errors for debugging
      error_log('Decryption error: ' . $e->getMessage());
      return NULL;
    }
  }

  /**
   * Handles file upload requests from clients.
   *
   * Orchestrates the file upload workflow: validates request path,
   * validates uploaded file, detects file type, and uploads to remote server.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request containing uploaded file data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with upload status and file details.
   */
  public function fileUploadser(Request $request)
  {
    // Get request path for validation
    $path = $request->getPathInfo();

    // Ensure request is to correct endpoint
    if ($path !== '/fileupload') {
      throw new NotFoundHttpException();
    }

    // Define form field name for file input
    define('UPLOAD_FILE', 'uploadedfile1');

    // Retrieve global configuration from Vault
    $globalVariables = $this->vaultConfigService->getGlobalVariables();

    // Validate uploaded file and return error if validation fails
    $result = $this->validateUploadedFile();
    if (!$result instanceof JsonResponse) {
      // File validation passed, proceed with upload process
      $result = $this->buildFileUploadResponse($request, $globalVariables);
    }

    return $result;
  }

  /**
   * Validates the uploaded file for security and format requirements.
   *
   * Checks that file exists and MIME type is in approved list.
   *
   * @return ?\Symfony\Component\HttpFoundation\JsonResponse
   *   JSON error response if validation fails, NULL if validation passes.
   */
  protected function validateUploadedFile(): ?JsonResponse
  {
    // Check if file was uploaded in expected field
    if (!isset($_FILES[UPLOAD_FILE])) {
      return new JsonResponse(['status' => FALSE, 'message' => 'No file uploaded.']);
    }

    // Detect MIME type of uploaded file from content
    $mimeType = mime_content_type($_FILES[UPLOAD_FILE]['tmp_name']);

    // Whitelist of allowed file MIME types
    $allowedTypes = [
      'image/jpeg',
      'image/png',
      'application/pdf',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'video/mp4'
    ];

    // Reject file if MIME type not in whitelist
    if (!in_array($mimeType, $allowedTypes)) {
      return new JsonResponse(['status' => FALSE, 'message' => 'File content not allowed!']);
    }

    // Validation passed
    return NULL;
  }

  /**
   * Detects file type category based on file extension.
   *
   * Maps file extensions to type IDs and category names used throughout system.
   *
   * @param string $extension
   *   File extension (e.g., 'jpg', 'pdf', 'mp4').
   *
   * @return ?array
   *   Array with ['id' => type_id, 'type' => category_name], or NULL if unknown.
   */
  protected function detectUploadedFileType(string $extension): ?array
  {
    // Convert extension to lowercase for case-insensitive comparison
    $extensionLower = strtolower($extension);

    // Initialize file type as NULL
    $fileType = NULL;

    // Detect image files - type ID 2
    if (in_array($extensionLower, ['jpg', 'jpeg', 'png'])) {
      $fileType = ['id' => 2, 'type' => 'image'];
    }
    // Detect document and audio files - type ID 4
    elseif (in_array($extensionLower, ['pdf', 'doc', 'docx', 'mp3', 'xlsx'])) {
      $fileType = ['id' => 4, 'type' => 'file'];
    }
    // Detect video files - type ID 1
    elseif ($extensionLower === 'mp4') {
      $fileType = ['id' => 1, 'type' => 'video'];
    }

    return $fileType;
  }

  /**
   * Builds file upload response after validation.
   *
   * Checks for multiple extensions, detects file type,
   * and initiates file upload to remote server.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request containing file data.
   * @param ?array $globalVariables
   *   Global configuration from Vault.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with upload result.
   */
  protected function buildFileUploadResponse(Request $request, ?array $globalVariables): JsonResponse
  {
    // Get original filename
    $originalName = $_FILES[UPLOAD_FILE]['name'];

    // Extract file extension
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);

    // Split filename by dots to count extensions
    $extnParts = explode(".", $originalName);

    // Reject files with multiple extensions (security risk)
    if (count($extnParts) > 2) {
      return new JsonResponse(['message' => 'Multiple file extensions not allowed', self::STR_STS => FALSE]);
    }

    // Detect file type from extension
    $fileType = $this->detectUploadedFileType($extension);

    // Return error if file type not recognized
    if ($fileType === NULL) {
      return new JsonResponse(['message' => 'Unsupported file type.', self::STR_STS => FALSE]);
    }

    // Upload file and return result
    return $this->uploadProcessedFile($request, $globalVariables, $extension, $fileType);
  }

  /**
   * Uploads validated file to remote server and handles profile updates.
   *
   * Generates unique filename, uploads via multipart POST, and optionally
   * updates user profile picture if requested.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param array $globalVariables
   *   Global configuration from Vault.
   * @param string $extension
   *   File extension.
   * @param array $fileType
   *   File type info with id and type.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with file path and type information.
   */
  protected function uploadProcessedFile(Request $request, array $globalVariables, string $extension, array $fileType): JsonResponse
  {
    // Get temporary file path
    $tmpName = $_FILES[UPLOAD_FILE]['tmp_name'];

    // Generate unique filename using UUID + extension
    $uuidFilename = \Drupal::service('uuid')->generate() . '.' . $extension;

    // Get file upload path from configuration
    $fileUplPath = $globalVariables['applicationConfig']['config']['fileuploadPath'];

    // Detect MIME type for upload
    $mimeType = mime_content_type($tmpName);

    // Create successful response with file path and type
    $result = new JsonResponse([
      'fileName' => $fileUplPath . $uuidFilename,
      'fileTypeId' => $fileType['id'],
      'fileTypeVal' => $fileType['type'],
    ]);

    try {
      // Make POST request to remote upload endpoint
      $response = $this->httpClient->request('POST', $fileUplPath . 'upload_media_test1.php', [
        // Disable SSL verification for internal server
        'verify' => FALSE,
        // Use multipart form data for file upload
        'multipart' => [
          // File field
          [
            'name' => UPLOAD_FILE,
            'contents' => fopen($tmpName, 'r'),
            'filename' => $uuidFilename,
            'headers' => [
              'Content-Type' => $mimeType
            ]
          ],
          // Success status code field
          [
            'name' => 'success_action_status',
            'contents' => '200',
          ]
        ]
      ]);

      // Get raw response body for logging
      $responseBody = $response->getBody()->getContents();
      $this->logger->debug('Upload raw response: ' . $responseBody);

      // Check if this is a profile picture upload
      if ($request->request->get('userPic') === 'profilePic') {
        // Update user profile picture in API
        $result = $this->updateUserProfilePic($fileUplPath . $uuidFilename);
      }
    } catch (\Exception $e) {
      // Log upload errors
      $this->logger->error('File upload failed: @error', ['@error' => $e->getMessage()]);
      // Return error response
      $result = new JsonResponse(['status' => FALSE, 'message' => 'Upload error'], 500);
    }

    return $result;
  }

  /**
   * Updates user profile picture via API call.
   *
   * Sends profile picture URL and user details to API for profile update.
   * Updates session with new profile picture URL on success.
   *
   * @param string $profilePic
   *   URL of uploaded profile picture file.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with update status and profile picture URL.
   */
  public function updateUserProfilePic($profilePic)
  {
    try {
      // Retrieve global configuration from Vault
      $globalVariables = $this->vaultConfigService->getGlobalVariables();

      // Get session containing user API data
      $session = \Drupal::request()->getSession();

      // Extract user data from session, default to empty array
      $user_data = $session->get('api_redirect_result') ?? [];

      // Get Apiman access token for API authentication
      $access_token = $this->apimanTokenService->getApimanAccessToken();

      // Check if mobile number exists in session
      if (empty($user_data['mobileNumber'])) {
        return new JsonResponse(['status' => FALSE, 'message' => 'Mobile number not found in session'], 400);
      }

      // Build payload with user details and profile picture
      $payload = [
        'mobileNumber' => $user_data['mobileNumber'],
        'profilePic' => $profilePic,
        'firstName' => $user_data['firstName'],
        'lastName' => $user_data['lastName'],
        'emailId' => $user_data['emailId'],
        'tenantCode' => $user_data['tenantCode'],
        'userId' => $user_data['userId']
      ];

      // Build API endpoint URL for user update
      $url = $globalVariables['apiManConfig']['config']['apiUrl'] . 'tiotcitizenapp' . $globalVariables['apiManConfig']['config']['apiVersion'] . 'user/update';

      // Log URL for debugging
      $this->logger->debug('Profile update URL: @url', ['@url' => $url]);

      // Make POST request to update profile picture
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Content-Type' => self::APP_JSON,
          'Authorization' => self::BEARER . $access_token,
        ],
        'json' => $payload,
        'timeout' => 10,
        // Disable SSL verification for dev
        'verify' => FALSE,
      ]);

      // Decode JSON response
      $result = json_decode($response->getBody()->getContents(), TRUE);

      // Log response for debugging
      $this->logger->debug('Profile update result:', [
        'Message' => json_encode($result['data']),
      ]);

      // Update session with new profile picture URL
      $session->set('api_redirect_result', array_merge($session->get('api_redirect_result', []), ['profilePic' => $result['data']['profilePic'] ?? NULL]));

      // Return success response with profile picture URL
      return new JsonResponse([
        'status' => TRUE,
        'profilePic' => $result['data']['profilePic'] ?? NULL,
      ]);
    } catch (\Exception $e) {
      // Log profile update errors
      $this->logger->error('Profile update failed: @msg', ['@msg' => $e->getMessage()]);

      // Return error response
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'Profile update failed',
      ], 500);
    }
  }

  /**
   * Updates user details and removes profile picture.
   *
   * Retrieves user data from session, calls API to update profile
   * with null profile picture, and clears session data on success.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with update status and message.
   */
  public function detailsUpdate()
  {
    // Log AJAX callback trigger
    \Drupal::logger('custom_profile_picture_form')->debug('AJAX Remove callback triggered.');

    // Get session object
    $session = \Drupal::request()->getSession();

    // Extract user data from session (API call result)
    $user_data = $session->get('api_redirect_result') ?? [];

    // Extract individual user fields from session
    $first_name = $user_data['firstName'];
    $last_name = $user_data['lastName'];
    $email = $user_data['emailId'] ?? '';
    $mobile = $user_data['mobileNumber'] ?? '';
    $user_id = $user_data['userId'] ?? '';

    // Build API payload with null profile picture
    $payload = [
      'firstName' => $first_name,
      'lastName' => $last_name,
      'emailId' => $email,
      'mobileNumber' => $mobile,
      'tenantCode' => $user_data['tenantCode'],
      'profilePic' => 'null',
      'userId' => $user_id
    ];

    try {
      // Get Apiman access token
      $access_token = $this->apimanTokenService->getApimanAccessToken();

      // Retrieve global configuration
      $globalVariables = $this->vaultConfigService->getGlobalVariables();

      // Get HTTP client
      $client = \Drupal::httpClient();

      // Make POST request to update user profile
      $response = $client->post(
        $globalVariables['apiManConfig']['config']['apiUrl'] . 'tiotcitizenapp' . $globalVariables['apiManConfig']['config']['apiVersion'] . 'user/update',
        [
          'headers' => [
            'Authorization' => self::BEARER . $access_token,
            'Content-Type' => self::APP_JSON,
          ],
          'json' => $payload,
        ]
      );

      // Decode JSON response
      $data = json_decode($response->getBody(), TRUE);

      // Check if API returned success status
      if (!empty($data['status']) && ($data['status'] === TRUE || $data['status'] === 'true')) {
        // Clear user session data on success
        $session->remove('api_redirect_result');

        // Log successful profile removal
        \Drupal::logger('custom_profile')->notice('Profile removed successfully.');

        // Return success response
        return new JsonResponse([
          'status' => TRUE,
          'message' => 'Profile removed successfully',
        ]);
      } else {
        // Log failed profile removal
        \Drupal::logger('custom_profile')->notice('Failed to remove profile');

        // Return failure response
        return new JsonResponse([
          'status' => FALSE,
          'message' => 'Failed to remove profile',
        ]);
      }
    } catch (\Exception $e) {
      // Log API errors
      \Drupal::logger('custom_profile_form')->error('API Error: @message', ['@message' => $e->getMessage()]);

      // Log generic error message
      \Drupal::logger('custom_profile_form')->error('API Error. Please try again later.');
    }
  }
}
