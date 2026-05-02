<?php

namespace Drupal\office_space_enquiry_api\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\Routing\Route;


/**
 * Access callbacks for office space enquiry REST routes.
 */
final class OfficeSpaceOAuthAccess {

  /**
   * Authenticated users with the enquiry API permission.
   *
   * Used for POST /rest/v1/office-space-enquiry and GET my-enquiries.
   */
  public static function enquiryApi(AccountInterface $account): AccessResult {
    $allowed = $account->isAuthenticated()
      && $account->hasPermission('use office space enquiry api');
    return AccessResult::allowedIf($allowed)
      ->cachePerPermissions()
      ->cachePerUser();
  }

  /**
   * Authenticated owner of a specific office_space_enquiry node.
   *
   * Used for GET /rest/v1/office-space-enquiry/{node}.
   * Ensures users can only read their own enquiry records.
   */
  public static function ownEnquiry(
    Route $route,
    RouteMatchInterface $route_match,
    AccountInterface $account,
  ): AccessResult {
    $base = static::enquiryApi($account);
    if (!$base->isAllowed()) {
      return $base;
    }

    $node = $route_match->getParameter('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'office_space_enquiry') {
      return AccessResult::forbidden();
    }

    if ((int) $node->getOwnerId() !== (int) $account->id()) {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }

    return AccessResult::allowed()->addCacheableDependency($node);
  }

}
