<?php

namespace Drupal\login_logout\Service;

use Drupal\global_module\Service\ApimanTokenService;
use Drupal\global_module\Service\VaultConfigService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Handles external user registration (API and SCIM).
 */
class UserRegistrationExternalService {
  use StringTranslationTrait;

  protected $httpClient;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $loggerFactory;
  protected $messenger;

  public function __construct(
    ClientInterface $httpClient,
    VaultConfigService $vaultConfigService,
    ApimanTokenService $apimanTokenService,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger
  ) {
    $this->httpClient = $httpClient;
    $this->vaultConfigService = $vaultConfigService;
    $this->apimanTokenService = $apimanTokenService;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
  }

  /**
   * Registers the user in the external portal API.
   */
  public function registerApiUser(array $data): bool {
    try {
      $accessToken = $this->apimanTokenService->getApimanAccessToken();
      $globalVariables = $this->vaultConfigService->getGlobalVariables();
      $endpoint = $globalVariables['apiManConfig']['config']['apiUrl']
        . 'tiotcitizenapp'
        . $globalVariables['apiManConfig']['config']['apiVersion']
        . 'user/register';

      $this->httpClient->request('POST', $endpoint, [
        'headers' => [
          'accept' => 'application/hal+json',
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $accessToken,
        ],
        'json' => [
          'firstName' => $data['first_name'],
          'lastName' => $data['last_name'],
          'mobileNumber' => $data['mobile'],
          'emailId' => $data['mail'],
          'tenantCode' => $globalVariables['applicationConfig']['config']['ceptenantCode'],
          'countryCode' => $data['country_code'],
        ],
        'verify' => FALSE,
      ]);

      return TRUE;
    } catch (RequestException $e) {
      $body = $e->getResponse()?->getBody()->getContents();
      $message = json_decode((string) $body, TRUE)['developerMessage'] ?? $e->getMessage();
      $this->messenger->addError($this->t('Registration failed: @msg', ['@msg' => $message]));
      return FALSE;
    }
  }

  /**
   * Registers the user in SCIM.
   */
  public function registerScimUser(array $data, string $password): void {
    try {
      $idamconfig = $this->vaultConfigService
        ->getGlobalVariables()['applicationConfig']['config']['idamconfig'];

      $this->httpClient->request('POST', 'https://' . $idamconfig . '/scim2/Users/', [
        'headers' => [
          'accept' => 'application/scim+json',
          'Content-Type' => 'application/scim+json',
          'Authorization' => 'Basic ' . base64_encode('trinity:trinity@123'),
        ],
        'json' => [
          'schemas' => [],
          'name' => [
            'givenName' => $data['first_name'],
            'familyName' => $data['last_name'],
          ],
          'userName' => $data['first_name'],
          'password' => $password,
          'emails' => [['value' => $data['mail']]],
          'phoneNumbers' => [['value' => $data['mobile'], 'type' => 'mobile']],
        ],
        'verify' => FALSE,
      ]);
    } catch (RequestException $e) {
      $this->loggerFactory->get('scim_user')->error('SCIM user creation failed: @error', ['@error' => $e->getMessage()]);
      $detail = json_decode((string) $e->getResponse()?->getBody()->getContents(), TRUE)['detail'] ?? '';
      $parts = explode('-', $detail, 2);
      $message = $parts[1] ?? $detail ?: $e->getMessage();
      $this->messenger->addError('Error: ' . $message);
    }
  }
}
