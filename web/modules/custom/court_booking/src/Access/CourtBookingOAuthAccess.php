<?php

namespace Drupal\court_booking\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access for OAuth2-protected court booking REST routes.
 */
final class CourtBookingOAuthAccess {

  /**
   * Public read-only availability endpoints.
   */
  public static function candidatesOrAvailability(AccountInterface $account): AccessResult {
    $isPublicRead = $account->isAnonymous() || $account->isAuthenticated();
    return AccessResult::allowedIf($isPublicRead)->cachePerPermissions();
  }

  /**
   * Add-to-cart style operations.
   */
  public static function addPermission(AccountInterface $account): AccessResult {
    $allowed = $account->isAuthenticated() && $account->hasPermission('use court booking add');
    return AccessResult::allowedIf($allowed)
      ->cachePerPermissions();
  }

}
