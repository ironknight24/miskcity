<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\custom_profile\Service\ServiceRequestApiService;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RequestDetailsController extends ControllerBase
{

    protected ServiceRequestApiService $service;

    public function __construct(ServiceRequestApiService $service)
    {
        $this->service = $service;
    }

    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('custom_profile.service_request_api')
        );
    }

    public function view(string $grievance_id, Request $request)
    {
        $requestTypeId = $request->query->get('type', 1);
        $session = \Drupal::service('session');

        $api_response = $this->service->getServiceRequestDetails($grievance_id, $requestTypeId);

        // Extract values safely
        $sessionUserId = $session->get('api_redirect_result')['userId'] ?? NULL;
        $responseUserId = $api_response['data']['serviceRequestDetails']['userId'] ?? NULL;

        // ✅ Check userId match
        if (!$sessionUserId || !$responseUserId || $sessionUserId !== $responseUserId) {
            // Redirect if not authorized
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
