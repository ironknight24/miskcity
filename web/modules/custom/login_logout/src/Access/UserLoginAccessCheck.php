<?php

namespace Drupal\login_logout\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Custom access check for /user-login.
 */
class UserLoginAccessCheck {

  /**
   * Redirect logged-in users, allow anonymous users.
   */
  public function access(AccountInterface $account) {
    if ($account->isAuthenticated()) {
      // Redirect authenticated users to homepage (or dashboard).
      $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
      $response->send();

      // Return forbidden so Drupal doesn't render the page.
      return AccessResult::forbidden();
    }

    // Allow access for anonymous users.
    return AccessResult::allowed();
  }

}
