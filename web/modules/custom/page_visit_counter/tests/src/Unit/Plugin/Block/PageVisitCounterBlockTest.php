<?php

namespace Drupal\Tests\page_visit_counter\Unit\Plugin\Block;

use Drupal\page_visit_counter\Plugin\Block\PageVisitCounterBlock;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\page_visit_counter\Plugin\Block\PageVisitCounterBlock
 * @group page_visit_counter
 */
class PageVisitCounterBlockTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * @var \Drupal\page_visit_counter\Plugin\Block\PageVisitCounterBlock
   */
  protected $block;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->createMock(Connection::class);

    $container = new ContainerBuilder();
    $container->set('database', $this->database);
    \Drupal::setContainer($container);

    $this->block = new PageVisitCounterBlock([], 'page_visit_counter_block', [], $this->database);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = \Drupal::getContainer();
    $block = PageVisitCounterBlock::create($container, [], 'page_visit_counter_block', []);
    $this->assertInstanceOf(PageVisitCounterBlock::class, $block);
  }

  /**
   * @covers ::build
   */
  public function testBuild() {
    $select = $this->createMock(SelectInterface::class);
    $statement = $this->createMock(StatementInterface::class);

    $this->database->method('select')->with('visitors', 'v')->willReturn($select);
    $select->method('addExpression')->willReturn($select);
    $select->method('condition')->willReturn($select);
    $select->method('where')->willReturn($select);
    $select->method('execute')->willReturn($statement);
    $statement->method('fetchField')->willReturn(1234);

    $build = $this->block->build();

    $this->assertEquals('page_visit_counter_block', $build['#theme']);
    $this->assertEquals('1234', $build['#count']);
    $this->assertEquals(0, $build['#cache']['max-age']);
  }

}
