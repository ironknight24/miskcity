<?php

namespace Drupal\event_booking\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\user\UserInterface;

/**
 * Resolves Drupal and portal identity values.
 */
class EventBookingAccountIdentity {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected GlobalVariablesService $globalVariables,
  ) {}

  public function getAccountEmail(AccountInterface $account): string {
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if ($user instanceof UserInterface) {
      return trim((string) $user->getEmail());
    }
    return trim((string) $account->getEmail());
  }

  /**
   * @param array<string, mixed> $payload
   */
  public function extractPortalEmail(array $payload, string $portal_user_id): ?string {
    $email = $this->extractEncryptedEmail($payload);
    if ($email !== NULL) {
      return $email;
    }
    if (!empty($payload['email']) && is_string($payload['email'])) {
      return $payload['email'];
    }
    return str_contains($portal_user_id, '@') ? $portal_user_id : NULL;
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function extractEncryptedEmail(array $payload): ?string {
    if (empty($payload['emailId']) || !is_string($payload['emailId'])) {
      return NULL;
    }
    try {
      $decrypted = $this->globalVariables->decrypt($payload['emailId']);
      return is_string($decrypted) && $decrypted !== '' ? $decrypted : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
