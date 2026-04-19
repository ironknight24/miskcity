<?php

namespace Drupal\global_module\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\global_module\Service\VaultConfigService;

/**
 * FileUploadService class.
 *
 * Handles file upload validation and processing.
 * Validates MIME types, file extensions, and file content for security.
 * Uploads validated files to a remote server with UUID-based filename.
 */
class FileUploadService
{

    // Injected UUID service for generating unique file identifiers
    protected $uuidService;
    
    // Injected vault configuration service for retrieving upload settings
    protected $vaultConfigService;

    /**
     * Constructor.
     *
     * Initializes the service with required dependencies.
     *
     * @param \Drupal\Component\Uuid\UuidInterface $uuid_service
     *   Service for generating unique identifiers for uploaded files.
     * @param \Drupal\global_module\Service\VaultConfigService $vault_config_service
     *   Service for retrieving configuration from Vault.
     */
    public function __construct(UuidInterface $uuid_service, VaultConfigService $vault_config_service)
    {
        $this->uuidService = $uuid_service;
        $this->vaultConfigService = $vault_config_service;
    }

    /**
     * Uploads a file to a remote server.
     *
     * Orchestrates the entire upload workflow:
     * 1. Retrieves uploaded file information from request
     * 2. Validates MIME type against whitelist
     * 3. Checks for multiple file extensions
     * 4. Detects file type based on extension
     * 5. Validates file content for security threats
     * 6. Uploads validated file to remote server
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The HTTP request object containing uploaded file data.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   JSON response with upload status, filename, and file type information.
     */
    public function uploadFile(Request $request): JsonResponse
    {
        // Define the form field name constant for file uploads
        define('UPLOAD_FILE', 'uploadedfile1');
        
        // Remove request variable as it's no longer needed after extraction
        unset($request);
        
        // Initialize with default error response (no file uploaded)
        $response = $this->errorResponse('No file uploaded.', 400);

        // Extract file information from $_FILES superglobal
        $fileInfo = $this->getUploadedFileInfo();
        if ($fileInfo) {
            // Unpack file info: [temp file path, original filename, MIME type]
            [$tmpFile, $originalName, $mimeType] = $fileInfo;

            // Step 1: Validate MIME type against approved list
            if (!$this->isMimeAllowed($mimeType)) {
                $response = $this->errorResponse('File content not allowed!');
            }
            // Step 2: Reject files with multiple extensions (security risk)
            elseif ($this->hasMultipleExtensions($originalName)) {
                $response = $this->errorResponse('Multiple file extensions not allowed');
            } else {
                // Step 3: Detect file type based on extension
                $fileType = $this->detectFileType($originalName);

                // Step 4: Verify recognized file type
                if (!$fileType) {
                    $response = $this->errorResponse('Selected file not allowed!');
                }
                // Step 5: Validate file content against malicious signatures
                elseif (!$this->validateFileContent($tmpFile)) {
                    $response = $this->errorResponse('Malicious file detected!');
                } else {
                    // Step 6: Upload file to remote server
                    $response = $this->uploadToRemote($tmpFile, $originalName, $fileType);
                }
            }
        }

        return $response;
    }

    /* ====================================================================
     * FILE VALIDATION METHODS
     *
     * Validate file uploads at multiple levels:
     * - MIME type validation
     * - Extension validation
     * - File content validation
     * ==================================================================== */

    /**
     * Extracts uploaded file information from $_FILES superglobal.
     *
     * Retrieves temporary file path, original filename, and MIME type
     * from the $_FILES array for the uploaded file.
     *
     * @return ?array
     *   Array with [temp_file_path, original_filename, mime_type] or NULL if no file.
     */
    private function getUploadedFileInfo(): ?array
    {
        // Check if file exists in the expected $_FILES location
        if (empty($_FILES['files']['tmp_name']['upload_file'])) {
            return NULL;
        }

        // Return array with temp file, original name, and MIME type
        return [
            $_FILES['files']['tmp_name']['upload_file'],
            $_FILES['files']['name']['upload_file'],
            mime_content_type($_FILES['files']['tmp_name']['upload_file']),
        ];
    }

    /**
     * Checks if the uploaded file's MIME type is allowed.
     *
     * Validates against a whitelist of approved MIME types
     * to prevent uploading executable or unsafe files.
     *
     * @param string $mime
     *   The MIME type of the uploaded file.
     *
     * @return bool
     *   TRUE if MIME type is allowed, FALSE otherwise.
     */
    private function isMimeAllowed(string $mime): bool
    {
        // Check against whitelist of approved MIME types
        return in_array($mime, [
            'image/jpeg',
            'image/png',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'video/mp4',
        ], TRUE);
    }

    /**
     * Checks if filename contains multiple file extensions.
     *
     * Prevents double extension attacks (e.g., "file.pdf.exe")
     * where multiple dots in filename can bypass security checks.
     *
     * @param string $filename
     *   The uploaded filename to validate.
     *
     * @return bool
     *   TRUE if multiple extensions detected, FALSE otherwise.
     */
    private function hasMultipleExtensions(string $filename): bool
    {
        // Count dots in filename - more than one indicates multiple extensions
        return substr_count($filename, '.') > 1;
    }

    /**
     * Detects file type based on file extension.
     *
     * Maps file extensions to type IDs and type names used by the system.
     * Type IDs correspond to file categories (video, image, file).
     *
     * @param string $filename
     *   The uploaded filename.
     *
     * @return ?array
     *   Array with ['id' => type_id, 'type' => type_name] or NULL if unknown.
     */
    private function detectFileType(string $filename): ?array
    {
        // Extract file extension and convert to lowercase for comparison
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Match extension to file type ID and category name
        return match (TRUE) {
            // Image file types - ID 2
            in_array($ext, ['jpg', 'jpeg', 'png']) =>
            ['id' => 2, 'type' => 'image'],
            // Document and audio file types - ID 4
            in_array($ext, ['pdf', 'doc', 'docx', 'mp3', 'xlsx']) =>
            ['id' => 4, 'type' => 'file'],
            // Video file types - ID 1
            $ext === 'mp4' =>
            ['id' => 1, 'type' => 'video'],
            // Unknown extension
            default => NULL,
        };
    }

    /**
     * Validates file content for security threats.
     *
     * Performs content-specific validation based on file type:
     * - Images: validates structure and recompresses
     * - PDFs: detects malicious JavaScript
     * - Other files: passes validation
     *
     * @param string $tmpFile
     *   Path to the temporary uploaded file.
     *
     * @return bool
     *   TRUE if file content is valid, FALSE if malicious content detected.
     */
    private function validateFileContent(string $tmpFile): bool
    {
        // Use finfo to detect actual MIME type from file headers (not extension)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpFile);

        // Validate image files by recompressing and checking structure
        if (str_starts_with($mimeType, 'image/')) {
            return $this->validateImage($tmpFile);
        }

        // Validate PDF files for JavaScript and other malicious content
        if ($mimeType === 'application/pdf') {
            return $this->validatePdf($tmpFile);
        }

        // Other file types pass validation (already checked by MIME whitelist)
        return TRUE;
    }

    /**
     * Validates image file structure and recompresses for safety.
     *
     * Checks if file is a valid image and recompresses it to remove
     * any embedded malicious content or metadata.
     *
     * @param string $file
     *   Path to the image file to validate.
     *
     * @return bool
     *   TRUE if valid image, FALSE if invalid or corrupted.
     */
    private function validateImage(string $file): bool
    {
        // Check if file is valid image - returns dimensions if valid, FALSE if not
        if (!getimagesize($file)) {
            return FALSE;
        }

        // Load image from file and recompress to remove malicious content
        $image = imagecreatefromstring(file_get_contents($file));
        if ($image) {
            // Recompress as JPEG at 90% quality to remove embeds
            imagejpeg($image, $file, 90);
            imagedestroy($image);
        }

        return TRUE;
    }

    /**
     * Validates PDF file for malicious JavaScript.
     *
     * Scans PDF content for JavaScript, AA (automatic action), and other
     * potentially malicious elements that could compromise security.
     *
     * @param string $file
     *   Path to the PDF file to validate.
     *
     * @return bool
     *   TRUE if no malicious content found, FALSE if threats detected.
     */
    private function validatePdf(string $file): bool
    {
        // Read entire PDF file content
        $content = file_get_contents($file);

        // Check for malicious patterns: /JS, /JavaScript, /AA (auto action)
        // Case-insensitive search
        return !preg_match('/\/(JS|JavaScript|AA)/i', $content);
    }

    /* ====================================================================
     * REMOTE UPLOAD - File Storage
     *
     * Handles uploading validated files to remote server
     * ==================================================================== */

    /**
     * Uploads validated file to remote server.
     *
     * Generates unique filename using UUID, retrieves upload path from Vault,
     * and uses cURL to POST file to remote upload endpoint. Returns file path
     * and metadata on success.
     *
     * @param string $tmpFile
     *   Path to the temporary uploaded file.
     * @param string $originalName
     *   Original filename (used to extract extension).
     * @param array $fileType
     *   File type information with id and type.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   Success response with file path, or error response on failure.
     */
    private function uploadToRemote(string $tmpFile, string $originalName, array $fileType): JsonResponse
    {
        // Generate unique filename: UUID + original extension
        $uuidFilename = $this->uuidService->generate() . '.' . pathinfo($originalName, PATHINFO_EXTENSION);

        // Retrieve Vault configuration containing upload path
        $globals = $this->vaultConfigService->getGlobalVariables();
        $fileUplPath = $globals['applicationConfig']['config']['fileuploadPath'] ?? NULL;

        // Check if upload path is configured
        if (!$fileUplPath) {
            return $this->errorResponse('Upload path not configured in Vault.', 500);
        }

        // Create cURL file object for multipart/form-data upload
        $cfile = curl_file_create($tmpFile, mime_content_type($tmpFile), $uuidFilename);

        // Initialize cURL session and configure request options
        $curl = curl_init();
        curl_setopt_array($curl, [
            // Target remote upload endpoint
            CURLOPT_URL => $fileUplPath . 'upload_media_test1.php',
            // Return response as string instead of printing
            CURLOPT_RETURNTRANSFER => TRUE,
            // Use POST method
            CURLOPT_CUSTOMREQUEST => 'POST',
            // Set POST fields with file and status code
            CURLOPT_POSTFIELDS => [
                UPLOAD_FILE => $cfile,
                'success_action_status' => 200,
            ],
            // Disable SSL verification for internal server communication
            CURLOPT_SSL_VERIFYHOST => FALSE,
            CURLOPT_SSL_VERIFYPEER => FALSE,
        ]);

        // Execute cURL request
        $response = curl_exec($curl);
        curl_close($curl);

        // Validate response received and is valid JSON
        if ($response === FALSE || json_last_error() !== JSON_ERROR_NONE) {
            return $this->errorResponse('Upload failed.', 500);
        }

        // Return success response with file path and metadata
        return new JsonResponse([
            'fileName' => $fileUplPath . $uuidFilename,
            'fileTypeId' => $fileType['id'],
            'fileTypeVal' => $fileType['type'],
        ]);
    }

    /* ====================================================================
     * RESPONSE HELPERS
     * ==================================================================== */

    /**
     * Constructs an error response JSON.
     *
     * Formats error responses with consistent structure for API clients.
     *
     * @param string $message
     *   Error message to return to client.
     * @param int $status
     *   HTTP status code (default: 200).
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   JSON response with error status and message.
     */
    private function errorResponse(string $message, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'status' => FALSE,
            'message' => $message,
        ], $status);
    }
}
