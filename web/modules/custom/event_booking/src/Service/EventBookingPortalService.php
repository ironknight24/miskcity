<?php

namespace Drupal\event_booking\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\event_booking\Portal\PortalUserClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Portal identity workflows for event booking APIs.
 */
class EventBookingPortalService extends EventBookingBaseService
{

  public function __construct(
    protected EventBookingAccountIdentity $accountIdentity,
    protected PortalUserClientInterface $portalUserClient,
    protected LoggerInterface $logger,
  ) {}

  public function verifyPortalUser(AccountInterface $account, string $portal_user_id): array
  {
    $response = NULL;

    $error = $this->validateAuthenticatedAccount($account);

    if ($error !== NULL) {
      $response = $error;
    } else {
      $drupal_email = $this->accountIdentity->getAccountEmail($account);

      if ($drupal_email === '') {
        $response = [
          'status' => 400,
          'data' => [
            'message' => (string) $this->t('Current account has no email.')
          ]
        ];
      } else {
        $error = $this->validatePortalConfiguration();

        if ($error !== NULL) {
          $response = $error;
        } else {
          $response = $this->verifyPortalPayload($account, $portal_user_id, $drupal_email);
        }
      }
    }

    return $response;
  }

  public function resolvePortalUserContext(AccountInterface $account): array
  {
    $response = NULL;

    $error = $this->validateAuthenticatedAccount($account);

    if ($error !== NULL) {
      $response = $error;
    } else {
      $drupal_email = $this->accountIdentity->getAccountEmail($account);

      if ($drupal_email === '') {
        $response = [
          'status' => 400,
          'data' => [
            'message' => (string) $this->t('Current account has no email.')
          ]
        ];
      } else {
        $error = $this->validatePortalConfiguration();

        if ($error !== NULL) {
          $response = $error;
        } else {
          $response = $this->resolvePortalPayload($drupal_email);
        }
      }
    }

    return $response;
  }

  private function validateAuthenticatedAccount(AccountInterface $account): ?array
  {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    return NULL;
  }

  private function validatePortalConfiguration(): ?array
  {
    if (!$this->portalUserClient->isConfigured()) {
      return ['status' => 503, 'data' => ['message' => (string) $this->t('Portal API is not configured.')]];
    }
    return NULL;
  }

  private function verifyPortalPayload(AccountInterface $account, string $portal_user_id, string $drupal_email): array
  {
    try {
      $payload = $this->portalUserClient->fetchByIdentifier($portal_user_id);
      if ($payload === NULL) {
        return ['status' => 404, 'data' => ['message' => (string) $this->t('Portal user not found for the given id.')]];
      }
      return $this->buildVerifyResponse($account, $payload, $portal_user_id, $drupal_email);
    } catch (\Throwable $e) {
      $this->logger->error('event_booking portal verify failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 503, 'data' => ['message' => (string) $this->t('Portal verification service unavailable.')]];
    }
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function buildVerifyResponse(AccountInterface $account, array $payload, string $portal_user_id, string $drupal_email): array
  {
    $resolved_email = $this->accountIdentity->extractPortalEmail($payload, $portal_user_id);
    if ($resolved_email === NULL) {
      return ['status' => 502, 'data' => ['message' => (string) $this->t('Portal response did not include a verifiable email.')]];
    }
    if (mb_strtolower(trim($resolved_email)) !== mb_strtolower(trim($drupal_email))) {
      $this->logger->notice('event_booking portal verify mismatch for uid @uid.', ['@uid' => $account->id()]);
      return ['status' => 403, 'data' => ['message' => (string) $this->t('Portal identity does not match the authenticated user.')]];
    }
    $return_user_id = $payload['userId'] ?? $portal_user_id;
    return ['status' => 200, 'data' => [
      'verified' => TRUE,
      'portal_user_id' => is_scalar($return_user_id) ? (string) $return_user_id : $portal_user_id,
      'email' => $drupal_email,
    ]];
  }

  private function resolvePortalPayload(string $drupal_email): array
  {
    try {
      $payload = $this->portalUserClient->fetchByIdentifier($drupal_email);
      if ($payload === NULL) {
        return ['status' => 404, 'data' => ['message' => (string) $this->t('Portal user not found for this account.')]];
      }
      return $this->buildContextResponse($payload, $drupal_email);
    } catch (\Throwable $e) {
      $this->logger->error('event_booking portal context failed: @msg', ['@msg' => $e->getMessage()]);
      return ['status' => 503, 'data' => ['message' => (string) $this->t('Portal context service unavailable.')]];
    }
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function buildContextResponse(array $payload, string $drupal_email): array
  {
    $response = NULL;

    $resolved_email = $this->accountIdentity->extractPortalEmail($payload, $drupal_email);

    if ($resolved_email === NULL) {
      $response = [
        'status' => 502,
        'data' => [
          'message' => (string) $this->t('Portal response did not include a verifiable email.')
        ]
      ];
    } elseif (mb_strtolower(trim($resolved_email)) !== mb_strtolower(trim($drupal_email))) {
      $response = [
        'status' => 403,
        'data' => [
          'message' => (string) $this->t('Portal profile email does not match the authenticated user.')
        ]
      ];
    } else {
      $portal_user_id = $this->extractPortalUserId($payload);

      if ($portal_user_id === NULL) {
        $response = [
          'status' => 502,
          'data' => [
            'message' => (string) $this->t('Portal response did not include a userId.')
          ]
        ];
      } elseif ($portal_user_id === '') {
        $response = [
          'status' => 502,
          'data' => [
            'message' => (string) $this->t('Portal returned an empty userId.')
          ]
        ];
      } else {
        $response = [
          'status' => 200,
          'data' => [
            'portal_user_id' => $portal_user_id,
            'email' => $drupal_email,
            'source' => 'portal_user_details',
            'lookup' => 'drupal_account_email',
          ]
        ];
      }
    }

    return $response;
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function extractPortalUserId(array $payload): ?string
  {
    $raw_portal_id = $payload['userId'] ?? NULL;
    if ($raw_portal_id === NULL || is_array($raw_portal_id) || is_object($raw_portal_id)) {
      return NULL;
    }
    return trim((string) $raw_portal_id);
  }
}
