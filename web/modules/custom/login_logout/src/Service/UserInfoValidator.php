<?php

namespace Drupal\login_logout\Service;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;

class UserInfoValidator
{

  protected $httpClient;
  protected $logger;
  protected $session;
  protected $globalVariablesService;
  protected $vaultConfigService;

  public function __construct(ClientInterface $http_client, LoggerInterface $logger, SessionInterface $session, GlobalVariablesService $globalVariablesService, VaultConfigService $vaultConfigService)
  {
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->session = $session;
    $this->globalVariablesService = $globalVariablesService;
    $this->vaultConfigService = $vaultConfigService;
  }

  /**
   * Validate the current session's access token with /userinfo.
   *
   * @return array|null
   *   Returns decoded user info if valid, or NULL if invalid.
   */
  public function validate()
  {
    $accessToken = $this->session->get('login_logout.access_token');
    $result = NULL;

    if (!$accessToken) {
      $this->logger->notice('No access token found in session.');
      return $result;
    }

    try {
      $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
      $response = $this->httpClient->request('POST', 'https://' . $idamconfig . '/oauth2/userinfo', [
        'headers' => [
          'Content-Type'  => 'application/x-www-form-urlencoded',
          'Authorization' => 'Bearer ' . $accessToken,
        ],
        'verify' => FALSE,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (!empty($data['sub'])) {
        $result = $data;
      }
      else {
        $this->logger->warning('UserInfo check failed: no sub returned.');
      }
    } catch (\Exception $e) {
      $this->logger->error('UserInfo validation error: @msg', ['@msg' => $e->getMessage()]);
    }

    return $result;
  }
}
