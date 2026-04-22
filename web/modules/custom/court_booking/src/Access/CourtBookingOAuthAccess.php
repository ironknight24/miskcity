<?php

namespace Drupal\court_booking\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access for OAuth2-protected court booking REST routes.
 */
final class CourtBookingOAuthAccess {

  /**
   * Slot candidates or calendar: booking page or add permission.
   */
  public static function candidatesOrAvailability(AccountInterface $account): AccessResult {
    if ($account->hasPermission('access court booking page') || $account->hasPermission('use court booking add')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::forbidden()->cachePerPermissions();
  }

  /**
   * Add-to-cart style operations.
   */
  public static function addPermission(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'use court booking add')
      ->cachePerPermissions();
  }

}
