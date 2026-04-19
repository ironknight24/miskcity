<?php

namespace Drupal\Tests\global_module\Unit\Controller;

use Drupal\global_module\Controller\GlobalController;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\ApiGatewayService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * @coversDefaultClass \Drupal\global_module\Controller\GlobalController
 * @group global_module
 */
class GlobalControllerTest extends UnitTestCase {

  protected $globalService;
  protected $apiGatewayService;
  protected $controller;

  /**
   * {@inheritdoc}
   * @covers ::__construct
   */
  protected function setUp(): void {
    parent::setUp();

    $this->globalService = $this->createMock(GlobalVariablesService::class);
    $this->apiGatewayService = $this->createMock(ApiGatewayService::class);

    $this->controller = new GlobalController($this->globalService, $this->apiGatewayService);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = new ContainerBuilder();
    $container->set('global_module.global_variables', $this->globalService);
    $container->set('global_module.api_gateway', $this->apiGatewayService);

    $controller = GlobalController::create($container);
    $this->assertInstanceOf(GlobalController::class, $controller);
  }

  /**
   * @covers ::fileUpload
   */
  public function testFileUpload() {
    $request = new Request();
    $this->globalService->expects($this->once())->method('fileUploadser')->willReturn(new JsonResponse());
    $this->controller->fileUpload($request);
  }

  /**
   * @covers ::postData
   */
  public function testPostData() {
    $request = new Request();
    $this->apiGatewayService->expects($this->once())->method('postData')->willReturn(new JsonResponse());
    $this->controller->postData($request);
  }

  /**
   * @covers ::detailsUpdate
   */
  public function testDetailsUpdate() {
    $this->globalService->expects($this->once())->method('detailsUpdate')->willReturn(new JsonResponse());
    $this->controller->detailsUpdate();
  }

  /**
   * @covers ::fileUploadAccess
   */
  public function testFileUploadAccessAllowed() {
    $request = Request::create('/fileupload', 'POST');
    
    $container = new ContainerBuilder();
    $path_stack = $this->createMock(CurrentPathStack::class);
    $path_stack->method('getPath')->willReturn('/fileupload');
    $container->set('path.current', $path_stack);
    
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->method('hasPermission')->with('access content')->willReturn(TRUE);
    $container->set('current_user', $current_user);
    
    \Drupal::setContainer($container);

    $result = GlobalController::fileUploadAccess($request);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::fileUploadAccess
   */
  public function testFileUploadAccessForbiddenMethod() {
    $request = Request::create('/fileupload', 'GET');
    
    $container = new ContainerBuilder();
    $path_stack = $this->createMock(CurrentPathStack::class);
    $path_stack->method('getPath')->willReturn('/fileupload');
    $container->set('path.current', $path_stack);
    
    $current_user = $this->createMock(AccountProxyInterface::class);
    $container->set('current_user', $current_user);
    
    \Drupal::setContainer($container);

    $result = GlobalController::fileUploadAccess($request);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::fileUploadAccess
   */
  public function testFileUploadAccessForbiddenPath() {
    $request = Request::create('/other-path', 'POST');
    
    $container = new ContainerBuilder();
    $path_stack = $this->createMock(CurrentPathStack::class);
    $path_stack->method('getPath')->willReturn('/other-path');
    $container->set('path.current', $path_stack);
    
    $current_user = $this->createMock(AccountProxyInterface::class);
    $container->set('current_user', $current_user);
    
    \Drupal::setContainer($container);

    $result = GlobalController::fileUploadAccess($request);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::fileUploadAccess
   */
  public function testFileUploadAccessNoPermission() {
    $request = Request::create('/fileupload', 'POST');
    
    $container = new ContainerBuilder();
    $path_stack = $this->createMock(CurrentPathStack::class);
    $path_stack->method('getPath')->willReturn('/fileupload');
    $container->set('path.current', $path_stack);
    
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->method('hasPermission')->with('access content')->willReturn(FALSE);
    $container->set('current_user', $current_user);
    
    \Drupal::setContainer($container);

    $result = GlobalController::fileUploadAccess($request);
    $this->assertTrue($result->isForbidden());
  }
}
