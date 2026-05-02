<?php

namespace Drupal\office_space_enquiry_api\Authentication;

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
 * Bearer token authentication for office space enquiry REST endpoints.
 *
 * Accepts the access_token issued by the WSO2 IDAM login flow:
 *   Authorization: Bearer <access_token>
 *
 * Validation steps:
 *   1. Validate the access token via POST /oauth2/userinfo and extract "sub" (email).
 *   2. Verify the email exists in the tiotcitizenapp portal API.
 *   3. Load and return the active Drupal user account for that email.
 *
 * Userinfo responses are cached for 60 seconds (keyed on sha256 of the token)
 * to avoid a live IDAM round-trip on every request.
 */
final class OfficeSpaceBearerAuthProvider implements AuthenticationProviderInterface {

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

    $email = $this->resolveEmailFromAccessToken($token);
    if ($email === NULL) {
      return NULL;
    }

    try {
      $accessToken = $this->apimanTokenService->getApimanAccessToken();
      $globals = $this->vaultConfigService->getGlobalVariables();
      $apiUrl = $globals['apiManConfig']['config']['apiUrl'] ?? '';
      $apiVersion = $globals['apiManConfig']['config']['apiVersion'] ?? '';
      $portalExists = $this->oauthLoginService->checkEmailExists($email, $accessToken, $apiUrl, $apiVersion);

      if (!$portalExists) {
        $this->logger->warning('office_space_bearer: Email @email not found in portal API.', ['@email' => $email]);
        return NULL;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('office_space_bearer: Portal API validation failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }

    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $email]);
    $user = reset($users);

    if (!$user instanceof UserInterface || !$user->isActive()) {
      $this->logger->warning('office_space_bearer: No active Drupal user found for @email.', ['@email' => $email]);
      return NULL;
    }

    return $user;
  }

  /**
   * Resolves the user email from an OAuth2 access token via IDAM userinfo.
   */
  private function resolveEmailFromAccessToken(string $token): ?string {
    $cid = 'office_space_bearer:userinfo:' . hash('sha256', $token);

    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    try {
      $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'] ?? '';

      if ($idamconfig === '') {
        $this->logger->error('office_space_bearer: IDAM config is missing; cannot validate access token.');
        return NULL;
      }

      $response = $this->httpClient->request('POST', 'https://' . $idamconfig . '/oauth2/userinfo', [
        'headers' => [
          'Content-Type'  => 'application/x-www-form-urlencoded',
          'Authorization' => 'Bearer ' . $token,
        ],
        'verify' => FALSE,
      ]);

      $userinfo = json_decode((string) $response->getBody(), TRUE) ?? [];

      if (!empty($userinfo['sub'])) {
        $email = mb_strtolower(trim((string) $userinfo['sub']));
        $this->cache->set($cid, $email, time() + self::USERINFO_CACHE_TTL);
        return $email;
      }

      $this->logger->warning('office_space_bearer: IDAM userinfo returned no sub claim; token rejected.');
    }
    catch (\Throwable $e) {
      $this->logger->warning('office_space_bearer: userinfo validation failed: @msg', ['@msg' => $e->getMessage()]);
    }

    return NULL;
  }

}
