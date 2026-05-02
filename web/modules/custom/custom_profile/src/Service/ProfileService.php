<?php

namespace Drupal\custom_profile\Service;

use Drupal\Component\Serialization\Json;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

/**
 * Retrieves and sanitises family-member data from the citizen-app API.
 *
 * After fetching raw family-member records, this service decrypts sensitive
 * fields (email and contact number) and masks all but the last four digits of
 * the phone number and the first three characters of the email local-part
 * before returning data to callers. This ensures PII is never exposed in full
 * on the front end.
 */
class ProfileService
{

  /**
   * The HTTP client used to issue requests to the citizen-app API.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Provides encryption/decryption helpers and global site variables.
   *
   * @var \Drupal\global_module\Service\GlobalVariablesService
   */
  protected GlobalVariablesService $globalVariablesService;

  /**
   * Provides access to Vault-stored configuration such as API base URLs.
   *
   * @var \Drupal\global_module\Service\VaultConfigService
   */
  protected VaultConfigService $vaultConfigService;

  /**
   * Provides a short-lived bearer token for API Manager authentication.
   *
   * @var \Drupal\global_module\Service\ApimanTokenService
   */
  protected ApimanTokenService $apimanTokenService;

  /**
   * Constructs a ProfileService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\global_module\Service\GlobalVariablesService $globalVariablesService
   *   The global variables / encryption service.
   * @param \Drupal\global_module\Service\VaultConfigService $vaultConfigService
   *   The Vault configuration service.
   * @param \Drupal\global_module\Service\ApimanTokenService $apimanTokenService
   *   The API Manager token service.
   */
  public function __construct(ClientInterface $http_client, GlobalVariablesService $globalVariablesService, VaultConfigService $vaultConfigService, ApimanTokenService $apimanTokenService)
  {
    $this->httpClient = $http_client;
    $this->globalVariablesService = $globalVariablesService;
    $this->vaultConfigService = $vaultConfigService;
    $this->apimanTokenService = $apimanTokenService;
  }

  /**
   * Factory method for the service container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   A new ProfileService instance.
   */
  public static function create(ContainerInterface $container): self
  {
    return new static(
      $container->get('http_client'),
      $container->get('global_module.global_variables'),
      $container->get('global_module.vault_config_service'),
      $container->get('global_module.apiman_token_service')
    );
  }

  /**
   * Fetches family members for a citizen user from the external API.
   *
   * The raw API response contains encrypted email and contact fields. This
   * method decrypts both, then applies display masking so callers receive a
   * safe, presentable version:
   *  - Email: first three characters preserved, next four replaced with ****,
   *    domain left intact (e.g. tes****@example.com).
   *  - Contact: all but the last four digits replaced with asterisks.
   *
   * @param string|int $user_id
   *   The citizen platform user ID whose family members should be retrieved.
   *
   * @return array
   *   An array of family-member records (each an associative array), or an
   *   empty array when the API call fails or returns no data.
   */
  public function fetchFamilyMembers($user_id): array
  {
    try {
      $globalVariables = $this->vaultConfigService->getGlobalVariables();
      $access_token = $this->apimanTokenService->getApimanAccessToken();

      $url = $globalVariables['apiManConfig']['config']['apiUrl'] .
        'tiotcitizenapp' .
        $globalVariables['apiManConfig']['config']['apiVersion'] .
        'family-members/fetch-family-member';

      $response = $this->httpClient->request('GET', $url . '/' . $user_id, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Accept' => 'application/json',
        ],
      ]);

      \Drupal::logger('custom_profile')->debug('URL: @url, Payload: @payload', [
        '@url' => $url,
        '@payload' => json_encode(['userId' => $user_id]),
      ]);

      $body = $response->getBody()->getContents();
      $data = Json::decode($body);

      if (isset($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as &$contact) {
          // Decrypt and mask email so only the first 3 characters are visible.
          if (!empty($contact['email'])) {
            $contact['email'] = $this->globalVariablesService->decrypt($contact['email']);
            $emailParts = explode('@', $contact['email']);
            if (count($emailParts) === 2) {
              $contact['email'] = substr($emailParts[0], 0, 3) . str_repeat('*', 4) . '@' . $emailParts[1];
            }
          }

          // Decrypt and mask contact number, showing only the last 4 digits.
          if (!empty($contact['contact'])) {
            $contact['contact'] = $this->globalVariablesService->decrypt($contact['contact']);
            $contact['contact'] = str_repeat('*', max(0, strlen($contact['contact']) - 4)) . substr($contact['contact'], -4);
          }
        }
      }

      return $data['data'] ?? [];
    } catch (RequestException $e) {
      \Drupal::logger('custom_profile')->error('Family fetch error: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

}
