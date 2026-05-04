<?php

namespace Drupal\event_booking\Service;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves event nodes related to purchased ticket variations.
 */
class EventBookingEventNodeResolver {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected LoggerInterface $logger,
  ) {}

  public function resolve(ProductVariationInterface $variation, ImmutableConfig $settings): ?NodeInterface {
    $node = $this->findByReverseReference($variation, $settings);
    return $node instanceof NodeInterface ? $node : $this->findViaLegacyVariationField($variation, $settings);
  }

  /**
   * @return array{0:string,1:bool}
   */
  public function resolveBundleForEventVariationField(string $bundle, string $field): array {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    if (isset($definitions[$field])) {
      return [$bundle, TRUE];
    }
    $storage_def = $this->entityFieldManager->getFieldStorageDefinitions('node')[$field] ?? NULL;
    if (!$storage_def instanceof FieldStorageConfigInterface) {
      return [$bundle, FALSE];
    }
    return $this->findBundleWithField($bundle, $field, $storage_def);
  }

  private function findByReverseReference(ProductVariationInterface $variation, ImmutableConfig $settings): ?NodeInterface {
    $bundle = trim((string) ($settings->get('event_node_bundle') ?: 'events'));
    $field = trim((string) ($settings->get('event_ticket_variation_field') ?: 'field_prod_event_variation'));
    if ($bundle === '' || $field === '') {
      return NULL;
    }
    [$resolved_bundle, $has_field] = $this->resolveBundleForEventVariationField($bundle, $field);
    if (!$has_field) {
      $this->logger->warning('event_booking receipt: field @field not found on any node bundle (tried @bundle).', ['@field' => $field, '@bundle' => $bundle]);
      return NULL;
    }
    $this->logBundleFallback($bundle, $resolved_bundle, $field);
    return $this->loadFirstNode($resolved_bundle, $field, (int) $variation->id());
  }

  private function findViaLegacyVariationField(ProductVariationInterface $variation, ImmutableConfig $settings): ?NodeInterface {
    $legacy = trim((string) ($settings->get('variation_event_reference_field') ?: ''));
    if ($legacy === '' || !$variation->hasField($legacy) || $variation->get($legacy)->isEmpty()) {
      return NULL;
    }
    $nid = (int) $variation->get($legacy)->target_id;
    $node = $nid > 0 ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
    return $node instanceof NodeInterface ? $node : NULL;
  }

  private function loadFirstNode(string $bundle, string $field, int $variation_id): ?NodeInterface {
    $nids = $this->queryEventNodeNidsByVariation($bundle, $field, $variation_id);
    if (!$nids) {
      return NULL;
    }
    if (count($nids) > 1) {
      $this->logger->notice('event_booking receipt: multiple event nodes reference variation @vid; using lowest nid.', ['@vid' => (string) $variation_id]);
    }
    $node = $this->entityTypeManager->getStorage('node')->load((int) $nids[0]);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * @return int[]
   */
  private function queryEventNodeNidsByVariation(string $bundle, string $field, int $variation_id): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $apply = static function ($q) use ($bundle, $field, $variation_id): void {
      $q->condition('type', $bundle)->condition($field . '.target_id', $variation_id)->sort('nid', 'ASC')->range(0, 2);
    };
    $q = $storage->getQuery()->accessCheck(TRUE);
    $apply($q);
    $nids = $q->execute();
    if (!$nids) {
      $q2 = $storage->getQuery()->accessCheck(FALSE);
      $apply($q2);
      $nids = $q2->execute() ?: [];
    }
    return array_values($nids);
  }

  /**
   * @return array{0:string,1:bool}
   */
  private function findBundleWithField(string $fallback_bundle, string $field, FieldStorageConfigInterface $storage_def): array {
    foreach (array_values(array_unique($storage_def->getBundles())) as $candidate) {
      $defs = $this->entityFieldManager->getFieldDefinitions('node', $candidate);
      if (isset($defs[$field])) {
        return [$candidate, TRUE];
      }
    }
    return [$fallback_bundle, FALSE];
  }

  private function logBundleFallback(string $configured_bundle, string $resolved_bundle, string $field): void {
    if ($resolved_bundle !== $configured_bundle) {
      $this->logger->notice('event_booking receipt: using node bundle @bundle for field @field (configured @original had no definition).', [
        '@bundle' => $resolved_bundle,
        '@field' => $field,
        '@original' => $configured_bundle,
      ]);
    }
  }

}
