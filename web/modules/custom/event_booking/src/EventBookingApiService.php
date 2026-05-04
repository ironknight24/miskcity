<?php

namespace Drupal\event_booking;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\event_booking\Service\EventBookingBookingsService;
use Drupal\event_booking\Service\EventBookingCartService;
use Drupal\event_booking\Service\EventBookingPortalService;
use Drupal\event_booking\Service\EventBookingReceiptService;

/**
 * Stable facade for event ticket REST API business logic.
 */
class EventBookingApiService {

  use StringTranslationTrait {
    setStringTranslation as private setFacadeStringTranslation;
  }

  public function __construct(
    protected EventBookingPortalService $portalService,
    protected EventBookingCartService $cartService,
    protected EventBookingReceiptService $receiptService,
    protected EventBookingBookingsService $bookingsService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function setStringTranslation(TranslationInterface $translation): void {
    $this->setFacadeStringTranslation($translation);
    $this->portalService->setStringTranslation($translation);
    $this->cartService->setStringTranslation($translation);
    $this->receiptService->setStringTranslation($translation);
    $this->bookingsService->setStringTranslation($translation);
  }

  public function verifyPortalUser(AccountInterface $account, string $portal_user_id): array {
    return $this->portalService->verifyPortalUser($account, $portal_user_id);
  }

  public function resolvePortalUserContext(AccountInterface $account): array {
    return $this->portalService->resolvePortalUserContext($account);
  }

  public function addTickets(AccountInterface $account, array $data): array {
    return $this->cartService->addTickets($account, $data);
  }

  public function getCartPayload(AccountInterface $account): array {
    return $this->cartService->getCartPayload($account);
  }

  public function clearCart(AccountInterface $account): array {
    return $this->cartService->clearCart($account);
  }

  public function buildReceipt(OrderInterface $order, AccountInterface $account): array {
    return $this->receiptService->buildReceipt($order, $account);
  }

  public function getTicketVariationPricing(AccountInterface $account, ProductVariationInterface $variation): array {
    return $this->cartService->getTicketVariationPricing($account, $variation);
  }

  public function getMyBookedEvents(AccountInterface $account, string $bucket, array $params): array {
    return $this->bookingsService->getMyBookedEvents($account, $bucket, $params);
  }

  public function getUnifiedBookings(AccountInterface $account, array $params): array {
    return $this->bookingsService->getUnifiedBookings($account, $params);
  }

}
