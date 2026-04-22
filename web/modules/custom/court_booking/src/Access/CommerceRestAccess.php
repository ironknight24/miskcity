<?php

namespace Drupal\court_booking\Access;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Access for Commerce REST helpers (cart + checkout).
 */
final class CommerceRestAccess {

  /**
   * Authenticated users with checkout permission.
   */
  public static function checkout(AccountInterface $account): AccessResult {
    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden()->cachePerUser();
    }
    return AccessResult::allowedIfHasPermission($account, 'access checkout')
      ->cachePerPermissions()
      ->cachePerUser();
  }

  /**
   * Checkout permission + own order (OAuth clients use authenticated users).
   */
  public static function ownDraftOrder(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $base = static::checkout($account);
    if (!$base->isAllowed()) {
      return $base;
    }
    $order = $route_match->getParameter('commerce_order');
    if (!$order instanceof OrderInterface) {
      return AccessResult::forbidden();
    }
    if ((int) $order->getCustomerId() !== (int) $account->id()) {
      return AccessResult::forbidden()
        ->addCacheableDependency($order);
    }
    if ($order->getState()->getId() === 'canceled') {
      return AccessResult::forbidden()
        ->addCacheableDependency($order);
    }
    return AccessResult::allowed()
      ->addCacheableDependency($order);
  }

}
