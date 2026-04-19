<?php

namespace Drupal\custom_profile\Service;

use Drupal\Component\Serialization\Json;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;

class ProfileService
{

    protected ClientInterface $httpClient;
    protected GlobalVariablesService $globalVariablesService;
    protected VaultConfigService $vaultConfigService;
    protected ApimanTokenService $apimanTokenService;

    public function __construct(ClientInterface $http_client, GlobalVariablesService $globalVariablesService, VaultConfigService $vaultConfigService, ApimanTokenService $apimanTokenService)
    {
        $this->httpClient = $http_client;
        $this->globalVariablesService = $globalVariablesService;
        $this->vaultConfigService = $vaultConfigService;
        $this->apimanTokenService = $apimanTokenService;
    }

    public static function create(ContainerInterface $container): self
    {
        return new static(
            $container->get('http_client'),
            $container->get('global_module.global_variables'),
            $container->get('global_module.vault_config_service'),
            $container->get('global_module.apiman_token_service')
        );
    }

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
                    // Decrypt and mask email
                    if (!empty($contact['email'])) {
                        $contact['email'] = $this->globalVariablesService->decrypt($contact['email']);
                        $emailParts = explode('@', $contact['email']);
                        if (count($emailParts) === 2) {
                            $contact['email'] = substr($emailParts[0], 0, 3) . str_repeat('*', 4) . '@' . $emailParts[1];
                        }
                    }

                    // Decrypt and mask contact
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
