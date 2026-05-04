<?php

namespace Drupal\event_booking\Service;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Builds pricing payloads for ticket variations.
 */
class EventBookingTicketPricingService extends EventBookingBaseService {

  public function __construct(
    protected EventBookingStoreResolver $storeResolver,
  ) {}

  public function getTicketVariationPricing(AccountInterface $account, ProductVariationInterface $variation): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    $store = $this->storeResolver->loadEventStore();
    if (!$store instanceof StoreInterface) {
      return ['status' => 500, 'data' => ['message' => (string) $this->t('Event store is not configured or invalid.')]];
    }
    $error = $this->validateVariation($variation, $store);
    return $error ?? ['status' => 200, 'data' => $this->buildPricePayload($variation)];
  }

  private function validateVariation(ProductVariationInterface $variation, StoreInterface $store): ?array {
    if (!$variation->isPublished()) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('Ticket variation not found or not available.')]];
    }
    if (!$this->storeResolver->variationBelongsToStore($variation, $store)) {
      return ['status' => 404, 'data' => ['message' => (string) $this->t('This ticket is not sold in the configured event store.')]];
    }
    return NULL;
  }

  private function buildPricePayload(ProductVariationInterface $variation): array {
    $price = $variation->getPrice();
    $data = [
      'variation_id' => (int) $variation->id(),
      'title' => $variation->getTitle(),
      'sku' => $variation->getSku(),
      'published' => $variation->isPublished(),
      'price' => $price ? $price->__toString() : NULL,
      'price_number' => $price ? $price->getNumber() : NULL,
      'currency_code' => $price ? $price->getCurrencyCode() : NULL,
      'list_price' => NULL,
      'list_price_number' => NULL,
      'list_currency_code' => NULL,
    ];
    return $this->appendListPrice($variation, $data);
  }

  private function appendListPrice(ProductVariationInterface $variation, array $data): array {
    if (!method_exists($variation, 'getListPrice') || !$variation->getListPrice()) {
      return $data;
    }
    $list = $variation->getListPrice();
    $data['list_price'] = $list->__toString();
    $data['list_price_number'] = $list->getNumber();
    $data['list_currency_code'] = $list->getCurrencyCode();
    return $data;
  }

}
