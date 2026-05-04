<?php

namespace Drupal\event_booking\Service;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * Builds event image payloads.
 */
class EventBookingImageBuilder {

  public function __construct(
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * @return array{url: string, alt: string}|null
   */
  public function buildImageData(NodeInterface $node, string $image_field): ?array {
    if (!$node->hasField($image_field) || $node->get($image_field)->isEmpty()) {
      return NULL;
    }
    $img_item = $node->get($image_field)->first();
    $file = $img_item && $img_item->entity instanceof FileInterface ? $img_item->entity : NULL;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $vals = $img_item ? $img_item->getValue() : [];
    return [
      'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
      'alt' => (string) ($vals['alt'] ?? ''),
    ];
  }

}
