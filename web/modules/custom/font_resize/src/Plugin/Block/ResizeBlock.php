<?php

namespace Drupal\font_resize\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'ResizeBlock' block.
 *
 * This block displays font size control buttons (reduce, reset, increase)
 * that allow users to adjust the font size on the page. It attaches
 * JavaScript and CSS libraries for the font resizing functionality.
 *
 * @Block(
 *   id = "resize_block",
 *   admin_label = @Translation("Resize block"),
 * )
 */
class ResizeBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * Builds the render array for the font resize block.
   * Returns theme template, button labels, and attaches required library.
   */
  public function build() {
    return [
      // Specify the theme template to use for rendering
      '#theme' => 'resize_block',
      // Provide translated labels for the resize buttons
      '#labels' => [
        'minus' => $this->t('Reduce Font Size'),
        'default' => $this->t('Reset Font Size'),
        'plus' => $this->t('Increase Font Size'),
      ],
      // Attach the font resize library (CSS/JS) to the page
      '#attached' => [
        'library' => ['font_resize/font_resize'],
      ],
    ];
  }
}
