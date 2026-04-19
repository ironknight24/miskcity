<?php

namespace Drupal\custom_profile\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\custom_profile\Service\ServiceRequestApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class MyServicesController extends ControllerBase
{

    protected ServiceRequestApiService $service;
    protected $request;

    public function __construct(
        ServiceRequestApiService $service,
        RequestStack $request_stack
    ) {
        $this->service = $service;
        $this->request = $request_stack->getCurrentRequest();
    }

    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('custom_profile.service_request_api'),
            $container->get('request_stack')
        );
    }

    public function view(Request $request)
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = 10;

        $session = $this->request->getSession();
        $userId = (int)($session->get('api_redirect_result')['userId']);

        // $userId = '2506101251005301'; // Replace with actual logic

        $search = $request->query->get('search', '');

        $api_response = $this->service->getServiceRequests($userId, $page, $itemsPerPage, $search);

        return [
            '#theme' => 'my_services_page', // <- must match the hook_theme name
            '#data' => $api_response['data'],
            '#total_count' => $api_response['data']['totalCount'],
            '#current_page' => $page,
            '#items_per_page' => $itemsPerPage,
            '#search' => $search,
        ];
    }
}
