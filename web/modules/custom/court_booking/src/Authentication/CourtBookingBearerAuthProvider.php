<?php

namespace Drupal\court_booking\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\login_logout\Service\OAuthLoginService;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bearer token authentication for court booking REST endpoints.
 *
 * Accepts the access_token issued by the WSO2 IDAM login flow
 * (returned by POST /rest/v1/auth/login):
 *   Authorization: Bearer <access_token>
 *
 * Validation steps:
 *   1. Validate the access token against /oauth2/userinfo and extract "sub"
 *      (email). This is a live IDAM call — if it fails the request is denied.
 *   2. Verify the email exists in the tiotcitizenapp portal API
 *      (the same check performed during the login_logout email step).
 *   3. Load and return the active Drupal user account for that email.
 *
 * No JWT fallback is used: if the IDAM userinfo call fails for any reason
 * (expired token, network error, revocation) the request is rejected.
 *
 * Userinfo responses are cached for 60 seconds keyed on a sha256 hash of the
 * token, so repeated API calls within the same short window do not each trigger
 * a live IDAM round-trip.
 */
final class CourtBookingBearerAuthProvider implements AuthenticationProviderInterface {

  /**
   * Cache TTL for userinfo responses (seconds).
   */
  private const USERINFO_CACHE_TTL = 60;

  public function __construct(
    protected OAuthLoginService $oauthLoginService,
    protected ClientInterface $httpClient,
    protected VaultConfigService $vaultConfigService,
    protected ApimanTokenService $apimanTokenService,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request): bool {
    return str_starts_with((string) $request->headers->get('Authorization', ''), 'Bearer ');
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request): ?AccountInterface {
    $token = trim(substr((string) $request->headers->get('Authorization', ''), 7));
    if ($token === '') {
      return NULL;
    }

    // Step 1: Validate access token via userinfo and extract the subject.
    $email = $this->resolve_email_from_access_token($token);

    if ($email === NULL) {
      return NULL;
    }

    // Step 2: Verify email exists in the tiotcitizenapp portal API.
    try {
      $accessToken = $this->apimanTokenService->getApimanAccessToken();
      $globals = $this->vaultConfigService->getGlobalVariables();
      $apiUrl = $globals['apiManConfig']['config']['apiUrl'] ?? '';
      $apiVersion = $globals['apiManConfig']['config']['apiVersion'] ?? '';
      $portalExists = $this->oauthLoginService->checkEmailExists($email, $accessToken, $apiUrl, $apiVersion);

      if (!$portalExists) {
        $this->logger->warning('court_booking_bearer: Email @email not found in portal API.', ['@email' => $email]);
        return NULL;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('court_booking_bearer: Portal API validation failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }

    // Step 3: Load the active Drupal user.
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $email]);
    $user = reset($users);

    if (!$user instanceof UserInterface || !$user->isActive()) {
      $this->logger->warning('court_booking_bearer: No active Drupal user found for @email.', ['@email' => $email]);
      return NULL;
    }

    return $user;
  }

  /**
   * Resolves the user email from an OAuth2 access token via IDAM userinfo.
   *
   * Calls POST /oauth2/userinfo on WSO2 IDAM with the bearer token and reads
   * the "sub" claim (the user's email). The successful result is cached for
   * USERINFO_CACHE_TTL seconds keyed on a sha256 hash of the token to avoid
   * a live IDAM round-trip on every API call. Returns NULL on any failure so
   * the caller can reject the request — no fallback decoding is attempted.
   */
  private function resolve_email_from_access_token(string $token): ?string {
    $cid = 'court_booking_bearer:userinfo:' . hash('sha256', $token);

    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    try {
      $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'] ?? '';

      if ($idamconfig === '') {
        $this->logger->error('court_booking_bearer: IDAM config is missing; cannot validate access token.');
        return NULL;
      }

      $response = $this->httpClient->request('POST', 'https://' . $idamconfig . '/oauth2/userinfo', [
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
          'Authorization' => 'Bearer ' . $token,
        ],
        'verify' => FALSE,
      ]);
      $statusCode = $response->getStatusCode();
      $userinfo = json_decode((string) $response->getBody(), TRUE) ?? [];

      if (!empty($userinfo['sub'])) {
        $email = mb_strtolower(trim((string) $userinfo['sub']));
        $this->cache->set($cid, $email, time() + self::USERINFO_CACHE_TTL);
        return $email;
      }

      $this->logger->warning('court_booking_bearer: IDAM userinfo returned no sub claim; token rejected.');
    }
    catch (\Throwable $e) {
      $this->logger->warning('court_booking_bearer: userinfo validation failed: @msg', ['@msg' => $e->getMessage()]);
    }

    return NULL;
  }

}
