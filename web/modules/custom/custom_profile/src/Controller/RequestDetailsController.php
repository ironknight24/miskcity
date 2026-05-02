<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\custom_profile\Service\ServiceRequestApiService;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the individual service-request detail page.
 *
 * Accepts a grievance ID from the URL, fetches the full request record from
 * the case-management API, and verifies that the requesting user owns the
 * record before rendering it. Unauthorized access silently redirects to the
 * service-request list.
 */
class RequestDetailsController extends ControllerBase
{

  /**
   * The service-request API client.
   *
   * @var \Drupal\custom_profile\Service\ServiceRequestApiService
   */
  protected ServiceRequestApiService $service;

  /**
   * Constructs a RequestDetailsController.
   *
   * @param \Drupal\custom_profile\Service\ServiceRequestApiService $service
   *   The API service for fetching individual request details.
   */
  public function __construct(ServiceRequestApiService $service)
  {
    $this->service = $service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('custom_profile.service_request_api')
    );
  }

  /**
   * Renders the detail page for a single service request.
   *
   * The request type defaults to 1 when not supplied via the "type" query
   * parameter. A userId comparison between the session and the API response
   * prevents users from viewing records that do not belong to them.
   *
   * @param string $grievance_id
   *   The grievance identifier extracted from the route path.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request, used to read the optional "type" query param.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array using the request_details_page theme hook on success,
   *   a plain markup array when no data is found, or a redirect to
   *   /service-request when the user does not own the record.
   */
  public function view(string $grievance_id, Request $request)
  {
    $requestTypeId = $request->query->get('type', 1);
    $session = \Drupal::service('session');

    $api_response = $this->service->getServiceRequestDetails($grievance_id, $requestTypeId);

    $sessionUserId  = $session->get('api_redirect_result')['userId'] ?? NULL;
    $responseUserId = $api_response['data']['serviceRequestDetails']['userId'] ?? NULL;

    // Redirect to the list if the session user does not match the record owner.
    if (!$sessionUserId || !$responseUserId || $sessionUserId !== $responseUserId) {
      return new RedirectResponse('/service-request');
    }

    if (empty($api_response['data'])) {
      return [
        '#markup' => $this->t('No data found for grievance ID @id.', ['@id' => $grievance_id]),
      ];
    }

    return [
      '#theme' => 'request_details_page',
      '#data' => $api_response['data'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
