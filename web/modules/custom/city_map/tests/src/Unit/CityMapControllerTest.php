<?php

namespace Drupal\Tests\city_map\Unit\Controller;

use Drupal\city_map\Controller\CityMapController;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Url;
use Drupal\file\FileInterface;

/**
 * @coversDefaultClass \Drupal\city_map\Controller\CityMapController
 * @group city_map
 */
class CityMapControllerTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\File\FileUrlGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileUrlGenerator;

  /**
   * @var \Drupal\city_map\Controller\CityMapController
   */
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('file_url_generator', $this->fileUrlGenerator);
    
    \Drupal::setContainer($container);

    $this->controller = new CityMapController();
  }

  /**
   * @covers ::cityMap
   */
  public function testCityMap() {
    $result = $this->controller->cityMap();
    $this->assertEquals('city_map', $result['#theme']);
    $this->assertArrayHasKey('library', $result['#attached']);
    $this->assertContains('city_map/city-map-library', $result['#attached']['library']);
  }

  /**
   * @covers ::getContentByTerm
   */
  public function testGetContentByTerm() {
    $tid = 1;
    
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(101);
    $node->method('label')->willReturn('POI Title');
    $node->method('getCreatedTime')->willReturn(1672531200);
    
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/node/101');
    $node->method('toUrl')->willReturn($url);

    // Mock fields
    $fields = [
      'field_address' => '123 Street',
      'field_contact_number' => '123456',
      'field_desc' => 'Description',
      'field_latitude' => '12.34',
      'field_longitude' => '56.78',
      'field_price' => 'Free',
      'field_timings' => '9-5',
      'field_website_url' => 'http://example.com',
    ];

    $node->method('get')->willReturnCallback(function($field_name) use ($fields, $node) {
      $field_mock = $this->getMockBuilder('\stdClass')
        ->addMethods(['isEmpty'])
        ->getMock();
      
      if ($field_name === 'field_image_1') {
        $field_mock->method('isEmpty')->willReturn(false);
        
        $file = $this->createMock(FileInterface::class);
        $file->method('getFileUri')->willReturn('public://image.jpg');
        
        $field_mock->entity = $file;
        return $field_mock;
      }

      $field_mock->value = $fields[$field_name] ?? '';
      return $field_mock;
    });

    $term_storage = $this->createMock(EntityStorageInterface::class);
    $term_storage->method('load')->willReturn(NULL);

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('loadByProperties')->with(['field_pois_category' => $tid])->willReturn([$node]);

    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['taxonomy_term', $term_storage],
      ['node', $node_storage],
    ]);

    $this->fileUrlGenerator->method('generateAbsoluteString')->willReturn('http://example.com/image.jpg');

    $response = $this->controller->getContentByTerm($tid);
    $this->assertInstanceOf(JsonResponse::class, $response);
    
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals(1, $data['count']);
    $this->assertEquals('POI Title', $data['items'][0]['title']);
    $this->assertEquals('http://example.com/image.jpg', $data['items'][0]['image_url']);
    $this->assertEquals('/node/101', $data['items'][0]['node_url']);
  }
}
