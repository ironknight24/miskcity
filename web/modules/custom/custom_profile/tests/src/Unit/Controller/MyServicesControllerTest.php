<?php

namespace Drupal\Tests\custom_profile\Unit\Controller;

use Drupal\custom_profile\Controller\MyServicesController;
use Drupal\custom_profile\Service\ServiceRequestApiService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * @coversDefaultClass \Drupal\custom_profile\Controller\MyServicesController
 * @group custom_profile
 */
class MyServicesControllerTest extends UnitTestCase {

  protected $apiService;
  protected $requestStack;
  protected $session;
  protected $controller;

  protected function setUp(): void {
    parent::setUp();

    $this->apiService = $this->createMock(ServiceRequestApiService::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->session = $this->createMock(SessionInterface::class);

    $current_request = $this->createMock(Request::class);
    $current_request->method('getSession')->willReturn($this->session);
    $this->requestStack->method('getCurrentRequest')->willReturn($current_request);

    $container = new ContainerBuilder();
    $container->set('custom_profile.service_request_api', $this->apiService);
    $container->set('request_stack', $this->requestStack);
    \Drupal::setContainer($container);

    $this->controller = new MyServicesController($this->apiService, $this->requestStack);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = \Drupal::getContainer();
    $controller = MyServicesController::create($container);
    $this->assertInstanceOf(MyServicesController::class, $controller);
  }

  /**
   * @covers ::view
   */
  public function testView() {
    $request = new Request();
    $request->query->set('page', 2);
    $request->query->set('search', 'test');

    $this->session->method('get')->with('api_redirect_result')->willReturn(['userId' => '123']);

    $api_data = [
      'data' => [
        'items' => [],
        'totalCount' => 100,
      ],
    ];
    $this->apiService->method('getServiceRequests')->willReturn($api_data);

    $result = $this->controller->view($request);

    $this->assertEquals('my_services_page', $result['#theme']);
    $this->assertEquals(2, $result['#current_page']);
    $this->assertEquals('test', $result['#search']);
    $this->assertEquals(100, $result['#total_count']);
  }

}
