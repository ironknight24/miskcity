<?php

namespace Drupal\login_logout\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Custom access check for /user-register.
 */
class UserRegisterAccessCheck
{

    /**
     * Redirect logged-in users, allow anonymous users.
     */
    public function access(AccountInterface $account)
    {
        if ($account->isAuthenticated()) {
            // Redirect authenticated users to homepage (or profile/dashboard).
            $response = new RedirectResponse(Url::fromRoute('<front>')->toString());
            $response->send();

            return AccessResult::forbidden();
        }

        return AccessResult::allowed();
    }
}
