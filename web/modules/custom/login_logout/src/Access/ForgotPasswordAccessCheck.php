<?php

namespace Drupal\login_logout\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Access\AccessResultReasonTrait;
use Drupal\Core\Routing\TrustedRedirectResponse;

/**
 * Custom access check for /forgot-password.
 */
class ForgotPasswordAccessCheck
{

    public function access(AccountInterface $account)
    {
        if ($account->isAuthenticated()) {
            $response = new TrustedRedirectResponse(Url::fromRoute('<front>')->toString());
            $response->send();
            return AccessResult::forbidden()->setCacheMaxAge(0);
        }

        return AccessResult::allowed();
    }
}
