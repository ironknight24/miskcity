<?php

namespace Drupal\global_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\ApiGatewayService;

/**
 * GlobalController class.
 *
 * Handles routing and delegation of global module requests to appropriate services.
 * Manages file uploads, data posting, and details updates through service layer.
 *
 * This controller acts as a router between HTTP requests and business logic services,
 * ensuring separation of concerns and maintainability.
 */
class GlobalController extends ControllerBase
{

  /**
   * The custom global service instance.
   *
   * Used for handling global module operations like file uploads and detail updates.
   *
   * @var \Drupal\global_module\Service\GlobalVariablesService
   */
  protected $globalService;

  /**
   * The API gateway service instance.
   *
   * Used for communicating with external APIs and posting data remotely.
   *
   * @var \Drupal\global_module\Service\ApiGatewayService
   */
  protected $apiGatewayService;

  /**
   * Constructs the controller with required services.
   *
   * Dependencies are injected by the Drupal service container to ensure
   * loose coupling and ease of testing.
   *
   * @param \Drupal\global_module\Service\GlobalVariablesService $globalService
   *   The global variables service for handling global operations.
   * @param \Drupal\global_module\Service\ApiGatewayService $apiGatewayService
   *   The API gateway service for external API communications.
   */
  public function __construct(GlobalVariablesService $globalService, ApiGatewayService $apiGatewayService)
  {
    // Store service references for use in controller methods
    $this->globalService = $globalService;
    $this->apiGatewayService = $apiGatewayService;
  }

  /**
   * Creates a new instance via dependency injection container.
   *
   * This factory method is called by Drupal's service container to instantiate
   * the controller with all required dependencies automatically resolved.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container providing access to registered services.
   *
   * @return static
   *   A new instance of the controller with injected dependencies.
   */
  public static function create(ContainerInterface $container)
  {
    // Retrieve services from the container using their registered service IDs
    return new static(
      $container->get('global_module.global_variables'),
      $container->get('global_module.api_gateway')
    );
  }

  /**
   * Handles file upload requests.
   *
   * This method receives HTTP file upload requests and delegates processing
   * to the global service layer.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request containing file data from the client.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with upload status and results for client consumption.
   */
  public function fileUpload(Request $request): JsonResponse
  {
    // Delegate file upload processing to the global service
    return $this->globalService->fileUploadser($request);
  }

  /**
   * Handles POST data requests to external APIs.
   *
   * Routes data posting requests through the API gateway service,
   * which manages communication with remote endpoints.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request with data to post to external services.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response from the API gateway containing results or errors.
   */
  public function postData(Request $request): JsonResponse
  {
    // Delegate API communication to the gateway service
    return $this->apiGatewayService->postData($request);
  }

  /**
   * Updates user or global details.
   *
   * Processes requests to update various user or system details
   * through the global service.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating success or failure of the update operation.
   */
  public function detailsUpdate(): JsonResponse
  {
    // Delegate detail update processing to the global service
    return $this->globalService->detailsUpdate();
  }

  /**
   * Access control callback for /fileupload endpoint.
   *
   * Validates incoming requests against security requirements before
   * allowing file upload operations to proceed.
   *
   * Validates:
   * - HTTP method must be POST (no GET, PUT, DELETE, etc.)
   * - Request path must exactly match /fileupload (case-insensitive)
   * - User must have 'access content' permission
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request to validate against access rules.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed or Forbidden access result based on validation rules.
   */
  public static function fileUploadAccess(Request $request)
  {
    // Initialize access as allowed, will be restricted if conditions fail
    $access = AccessResult::allowed();

    // Only allow POST requests - reject GET, PUT, DELETE, etc.
    if ($request->getMethod() !== 'POST') {
      $access = AccessResult::forbidden();
    }

    // Enforce exact path match (case-insensitive comparison for reliability)
    // This prevents file uploads through alternate URL paths
    $current_path = strtolower(\Drupal::service('path.current')->getPath());
    if ($current_path !== '/fileupload') {
      $access = AccessResult::forbidden();
    }

    // Verify user has permission to access content
    // This ensures only authorized users can initiate file uploads
    $current_user = \Drupal::currentUser();
    if (!$current_user->hasPermission('access content')) {
      $access = AccessResult::forbidden();
    }

    return $access;
  }
}
