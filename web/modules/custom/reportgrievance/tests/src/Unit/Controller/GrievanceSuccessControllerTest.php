<?php

namespace Drupal\Tests\reportgrievance\Unit\Controller;

use Drupal\reportgrievance\Controller\GrievanceSuccessController;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @coversDefaultClass \Drupal\reportgrievance\Controller\GrievanceSuccessController
 * @group reportgrievance
 */
class GrievanceSuccessControllerTest extends UnitTestCase {

  protected $kvFactory;
  protected $kvStore;
  protected $controller;

  protected function setUp(): void {
    parent::setUp();

    $this->kvFactory = $this->createMock(KeyValueFactoryInterface::class);
    $this->kvStore = $this->createMock(KeyValueStoreInterface::class);
    $this->kvFactory->method('get')->with('reportgrievance.token_map')->willReturn($this->kvStore);

    $container = new ContainerBuilder();
    $container->set('keyvalue', $this->kvFactory);
    \Drupal::setContainer($container);

    $this->controller = new GrievanceSuccessController();
  }

  /**
   * @covers ::content
   */
  public function testContentSuccess() {
    $token = 'valid_token';
    $grievance_id = 'GV-123';
    $this->kvStore->method('get')->with($token)->willReturn($grievance_id);

    $result = $this->controller->content($token);

    $this->assertEquals('grievance_success', $result['#theme']);
    $this->assertEquals($grievance_id, $result['#response_data']['grievance_id']);
  }

  /**
   * @covers ::content
   */
  public function testContentInvalidToken() {
    $token = 'invalid_token';
    $this->kvStore->method('get')->with($token)->willReturn(NULL);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Invalid token.');
    $this->controller->content($token);
  }

  /**
   * @covers ::content
   */
  public function testContentNoToken() {
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Invalid request.');
    $this->controller->content(NULL);
  }

}
