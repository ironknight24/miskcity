<?php

namespace Drupal\Tests\ideas\Unit\Controller;

use Drupal\ideas\Controller\IdeasFileUploadController;
use Drupal\global_module\Service\FileUploadService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @coversDefaultClass \Drupal\ideas\Controller\IdeasFileUploadController
 * @group ideas
 */
class IdeasFileUploadControllerTest extends UnitTestCase {

  protected $fileUploadService;
  protected $controller;

  /**
   * {@inheritdoc}
   * @covers ::__construct
   */
  protected function setUp(): void {
    parent::setUp();

    $this->fileUploadService = $this->createMock(FileUploadService::class);
    $this->controller = new IdeasFileUploadController($this->fileUploadService);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $controller = new IdeasFileUploadController($this->fileUploadService);
    $this->assertInstanceOf(IdeasFileUploadController::class, $controller);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = new ContainerBuilder();
    $container->set('global_module.file_upload_service', $this->fileUploadService);
    $controller = IdeasFileUploadController::create($container);
    $this->assertInstanceOf(IdeasFileUploadController::class, $controller);
  }

  /**
   * @covers ::upload
   */
  public function testUploadSuccess() {
    $request = new Request();
    $api_response = new JsonResponse(['fileName' => 'image.jpg']);
    $this->fileUploadService->method('uploadFile')->with($request)->willReturn($api_response);

    $response = $this->controller->upload($request);
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(json_encode(['fileUrl' => 'image.jpg']), $response->getContent());
  }

  /**
   * @covers ::upload
   */
  public function testUploadError() {
    $request = new Request();
    $api_response = new JsonResponse(['error' => 'Invalid file'], 400);
    $this->fileUploadService->method('uploadFile')->with($request)->willReturn($api_response);

    $response = $this->controller->upload($request);
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals(json_encode(['error' => 'Invalid file']), $response->getContent());
  }

}
