<?php

namespace Drupal\event_booking\Service;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves event store configuration and variation-store membership.
 */
class EventBookingStoreResolver {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function loadEventStore(): ?StoreInterface {
    $id = trim((string) $this->configFactory->get('event_booking.settings')->get('commerce_store_id'));
    if ($id === '') {
      return NULL;
    }
    $store = $this->entityTypeManager->getStorage('commerce_store')->load($id);
    return $store instanceof StoreInterface ? $store : NULL;
  }

  public function variationBelongsToStore(ProductVariationInterface $variation, StoreInterface $store): bool {
    $product = $variation->getProduct();
    if (!$product) {
      return FALSE;
    }
    foreach ($product->getStores() as $candidate) {
      if ((int) $candidate->id() === (int) $store->id()) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
