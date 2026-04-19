<?php

namespace Drupal\Tests\font_resize\Unit\Plugin\Block;

use Drupal\font_resize\Plugin\Block\ResizeBlock;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\font_resize\Plugin\Block\ResizeBlock
 * @group font_resize
 */
class ResizeBlockTest extends UnitTestCase {

  /**
   * @var \Drupal\font_resize\Plugin\Block\ResizeBlock
   */
  protected $block;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    // BlockBase __construct arguments are (array $configuration, $plugin_id, $plugin_definition)
    $this->block = new ResizeBlock([], 'resize_block', ['admin_label' => 'Resize block']);
  }

  /**
   * @covers ::build
   */
  public function testBuild() {
    $build = $this->block->build();

    $this->assertEquals('resize_block', $build['#theme']);
    $this->assertArrayHasKey('#labels', $build);
    $this->assertArrayHasKey('minus', $build['#labels']);
    $this->assertArrayHasKey('default', $build['#labels']);
    $this->assertArrayHasKey('plus', $build['#labels']);
    $this->assertContains('font_resize/font_resize', $build['#attached']['library']);
  }

}
