<?php

namespace Drupal\event_booking\Portal;

use Drupal\global_module\Service\ApimanTokenService;
use Drupal\global_module\Service\VaultConfigService;
use GuzzleHttp\ClientInterface;

/**
 * Default implementation for PortalUserClientInterface.
 */
final class PortalUserClient implements PortalUserClientInterface {

  public function __construct(
    private readonly ApimanTokenService $apimanTokenService,
    private readonly VaultConfigService $vaultConfigService,
    private readonly ClientInterface $httpClient,
  ) {}

  public function isConfigured(): bool {
    return $this->buildUrl() !== NULL;
  }

  public function fetchByIdentifier(string $identifier): ?array {
    $url = $this->buildUrl();
    if ($url === NULL) {
      return NULL;
    }

    $token = $this->apimanTokenService->getApimanAccessToken();
    $response = $this->httpClient->request('POST', $url, [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
      ],
      'json' => ['userId' => $identifier],
    ]);

    $decoded = json_decode((string) $response->getBody(), TRUE) ?? [];
    $payload = $decoded['data'] ?? NULL;
    if (!is_array($payload) || $payload === []) {
      return NULL;
    }
    return $payload;
  }

  /**
   * Builds the portal user details URL from vault configuration.
   */
  private function buildUrl(): ?string {
    $globals = $this->vaultConfigService->getGlobalVariables();
    $api_url = $globals['apiManConfig']['config']['apiUrl'] ?? '';
    $api_version = $globals['apiManConfig']['config']['apiVersion'] ?? '';
    if ($api_url === '' || $api_version === '') {
      return NULL;
    }
    return $api_url . 'tiotcitizenapp' . $api_version . 'user/details';
  }

}

