<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\custom_profile\Service\ServiceRequestApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for the My Services (service request list) page.
 *
 * Reads the current user ID from the session, delegates paginated retrieval
 * to ServiceRequestApiService, and returns a themed render array consumed by
 * the my-services-page Twig template.
 */
class MyServicesController extends ControllerBase
{

  /**
   * The service-request API client.
   *
   * @var \Drupal\custom_profile\Service\ServiceRequestApiService
   */
  protected ServiceRequestApiService $service;

  /**
   * The current HTTP request, used to read session data and query parameters.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Constructs a MyServicesController.
   *
   * @param \Drupal\custom_profile\Service\ServiceRequestApiService $service
   *   The API service for retrieving service requests.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack from which the current request is resolved.
   */
  public function __construct(
    ServiceRequestApiService $service,
    RequestStack $request_stack
  ) {
    $this->service = $service;
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('custom_profile.service_request_api'),
      $container->get('request_stack')
    );
  }

  /**
   * Renders the service-request list page with pagination and search support.
   *
   * The page number defaults to 1 and is clamped to a minimum of 1. The user
   * ID is read from the session key set during the login/redirect flow. An
   * optional "search" query parameter is forwarded to the API to filter results.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request, providing query parameters.
   *
   * @return array
   *   A render array using the my_services_page theme hook.
   */
  public function view(Request $request)
  {
    $page = max(1, (int) $request->query->get('page', 1));
    $itemsPerPage = 10;

    $session = $this->request->getSession();
    $userId = (int)($session->get('api_redirect_result')['userId']);

    $search = $request->query->get('search', '');

    $api_response = $this->service->getServiceRequests($userId, $page, $itemsPerPage, $search);

    return [
      '#theme' => 'my_services_page',
      '#data' => $api_response['data'],
      '#total_count' => $api_response['data']['totalCount'],
      '#current_page' => $page,
      '#items_per_page' => $itemsPerPage,
      '#search' => $search,
    ];
  }

}
