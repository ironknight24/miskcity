<?php

namespace Drupal\event_booking\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access callbacks for event booking REST routes.
 */
final class EventBookingOAuthAccess {

  /**
   * Authenticated users with event booking API permission.
   */
  public static function bookingApi(AccountInterface $account): AccessResult {
    $allowed = $account->isAuthenticated() && $account->hasPermission('use event booking api');
    return AccessResult::allowedIf($allowed)->cachePerPermissions();
  }

  /**
   * Authenticated users with either event or court booking list access.
   */
  public static function unifiedBookingsApi(AccountInterface $account): AccessResult {
    $allowed = $account->isAuthenticated() && (
      $account->hasPermission('use event booking api')
      || $account->hasPermission('use court booking add')
    );
    return AccessResult::allowedIf($allowed)->cachePerPermissions();
  }

}
