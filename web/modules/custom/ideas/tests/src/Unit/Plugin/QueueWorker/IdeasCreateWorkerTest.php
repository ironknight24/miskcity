<?php

namespace Drupal\Tests\ideas\Unit\Plugin\QueueWorker;

use Drupal\ideas\Plugin\QueueWorker\IdeasCreateWorker;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\ideas\Plugin\QueueWorker\IdeasCreateWorker
 * @group ideas
 */
class IdeasCreateWorkerTest extends UnitTestCase {

  /**
   * @covers ::processItem
   */
  public function testProcessItem() {
    $data = [
      'title' => 'Test Idea',
      'author' => 'Author',
      'body' => 'Body',
      'category_id' => '1',
      'image_url' => 'http://example.com/image.jpg',
      'uid' => 123,
    ];

    $node = $this->getMockBuilder(Node::class)->disableOriginalConstructor()->getMock();
    $node->expects($this->once())->method('save');

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('create')->willReturn($node);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')->willReturn($node_storage);

    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('node');

    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(\Drupal\Core\Logger\LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('ideas_queue')->willReturn($logger);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('entity_type.repository', $entity_type_repository);
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);

    $worker = new IdeasCreateWorker([], 'ideas_create_queue', []);
    $worker->processItem($data);
  }

}
