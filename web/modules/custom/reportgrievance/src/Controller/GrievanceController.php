<?php

namespace Drupal\reportgrievance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\reportgrievance\Service\GrievanceApiService;  // Import the correct service class

class GrievanceController extends ControllerBase
{
    protected $apiService;
    protected $cache;

    public function __construct(GrievanceApiService $apiService, CacheBackendInterface $cache) {
        $this->apiService = $apiService;
        $this->cache = $cache;
    }

    public static function create(ContainerInterface $container) {
        return new static(
            $container->get('reportgrievance.grievance_api'),  // This matches the service ID in your services.yml
            $container->get('cache.default')
        );
    }

    /**
     * Fetch grievance types.
     */
    public function getGrievanceTypes() {
        $cache = $this->cache->get('grievance_types');
        if ($cache) {
            return new JsonResponse($cache->data);
        }

        $types = $this->apiService->getIncidentTypes();
        if (!empty($types)) {
            $this->cache->set('grievance_types', $types, time() + 1800);
        }

        return new JsonResponse($types);
    }

    /**
     * Fetch grievance subtypes based on grievance type.
     */
    public function getGrievanceSubTypes($grievance_type_id) {
        $cache_key = 'grievance_subtypes_' . $grievance_type_id;
        $cache = $this->cache->get($cache_key);
        
        if ($cache) {
            return new JsonResponse($cache->data);
        }

        $subtypes = $this->apiService->getIncidentSubTypes((int) $grievance_type_id);
        if (!empty($subtypes)) {
            $this->cache->set($cache_key, $subtypes, time() + 1800);
        }

        return new JsonResponse($subtypes);
    }
}
