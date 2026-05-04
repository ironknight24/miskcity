<?php

namespace Drupal\event_booking\Service;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * Serializes custom node fields for event API responses.
 */
class EventBookingFieldSerializer {

  public function __construct(
    protected EventBookingDateFormatter $dateFormatter,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * @return array<string, array<int, array<string, mixed>>>
   */
  public function serializeNodeFields(NodeInterface $node): array {
    $fields = [];
    foreach ($node->getFields() as $field_name => $items) {
      $definition = $items->getFieldDefinition();
      $storage_definition = $definition->getFieldStorageDefinition();
      if ($storage_definition->isBaseField() || $this->isSkippedNodeField($field_name)) {
        continue;
      }
      $fields[$field_name] = $this->serializeFieldItemValues($items, (string) $definition->getType());
    }
    ksort($fields);
    return $fields;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function serializeFieldItemValues(iterable $items, string $field_type): array {
    $values = [];
    foreach ($items as $item) {
      $value = $this->dateFormatter->normalizeFieldDateValuesToSiteTimezone($item->getValue(), $field_type);
      if (isset($item->entity) && $item->entity instanceof FileInterface) {
        $value['url'] = $this->fileUrlGenerator->generateAbsoluteString($item->entity->getFileUri());
      }
      $values[] = $value;
    }
    return $values;
  }

  private function isSkippedNodeField(string $field_name): bool {
    static $skipped = [
      'nid', 'uuid', 'vid', 'type',
      'revision_timestamp', 'revision_uid', 'revision_log',
      'revision_default', 'revision_translation_affected',
      'langcode', 'default_langcode', 'status', 'uid', 'title',
      'created', 'changed', 'promote', 'sticky', 'path',
    ];
    return in_array($field_name, $skipped, TRUE) || str_starts_with($field_name, 'revision_');
  }

}
