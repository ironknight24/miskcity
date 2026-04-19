<?php

namespace Drupal\Tests\reportgrievance\Unit\Controller;

use Drupal\reportgrievance\Controller\GrievanceController;
use Drupal\reportgrievance\Service\GrievanceApiService;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @coversDefaultClass \Drupal\reportgrievance\Controller\GrievanceController
 * @group reportgrievance
 */
class GrievanceControllerTest extends UnitTestCase {

  protected $apiService;
  protected $cache;
  protected $controller;

  protected function setUp(): void {
    parent::setUp();

    $this->apiService = $this->createMock(GrievanceApiService::class);
    $this->cache = $this->createMock(CacheBackendInterface::class);

    $this->controller = new GrievanceController($this->apiService, $this->cache);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = new ContainerBuilder();
    $container->set('reportgrievance.grievance_api', $this->apiService);
    $container->set('cache.default', $this->cache);

    $controller = GrievanceController::create($container);
    $this->assertInstanceOf(GrievanceController::class, $controller);
  }

  /**
   * @covers ::getGrievanceTypes
   */
  public function testGetGrievanceTypesCacheHit() {
    $cache_data = ['1' => 'Type 1'];
    $cache_obj = (object) ['data' => $cache_data];
    $this->cache->method('get')->with('grievance_types')->willReturn($cache_obj);

    $response = $this->controller->getGrievanceTypes();
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(json_encode($cache_data), $response->getContent());
  }

  /**
   * @covers ::getGrievanceTypes
   */
  public function testGetGrievanceTypesCacheMiss() {
    $this->cache->method('get')->with('grievance_types')->willReturn(NULL);
    $api_data = ['2' => 'Type 2'];
    $this->apiService->method('getIncidentTypes')->willReturn($api_data);
    
    $this->cache->expects($this->once())->method('set')->with('grievance_types', $api_data, $this->anything());

    $response = $this->controller->getGrievanceTypes();
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(json_encode($api_data), $response->getContent());
  }

  /**
   * @covers ::getGrievanceSubTypes
   */
  public function testGetGrievanceSubTypesCacheHit() {
    $tid = 1;
    $cache_data = ['10' => 'Sub 10'];
    $cache_obj = (object) ['data' => $cache_data];
    $this->cache->method('get')->with('grievance_subtypes_1')->willReturn($cache_obj);

    $response = $this->controller->getGrievanceSubTypes($tid);
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(json_encode($cache_data), $response->getContent());
  }

  /**
   * @covers ::getGrievanceSubTypes
   */
  public function testGetGrievanceSubTypesCacheMiss() {
    $tid = 1;
    $this->cache->method('get')->with('grievance_subtypes_1')->willReturn(NULL);
    $api_data = ['11' => 'Sub 11'];
    $this->apiService->method('getIncidentSubTypes')->with(1)->willReturn($api_data);
    
    $this->cache->expects($this->once())->method('set')->with('grievance_subtypes_1', $api_data, $this->anything());

    $response = $this->controller->getGrievanceSubTypes($tid);
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(json_encode($api_data), $response->getContent());
  }
}
