<?php

namespace Drupal\login_logout\Service;

use Drupal\login_logout\Exception\OAuthLoginException;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\Core\Site\Settings;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;
use Drupal\login_logout\Service\OAuthHelperService;

class OAuthLoginService
{
    public const SECURE_LINK = 'https://';
    public const APP_JSON = 'application/json';
    public const FORM_URLENCODED = 'application/x-www-form-urlencoded';
    protected $httpClient;
    protected $logger;
    protected $requestStack;
    protected $globalVariablesService;
    protected $vaultConfigService;
    protected $apimanTokenService;
    protected $oauthHelperService;

    public function __construct(
        ClientInterface $http_client,
        LoggerInterface $logger,
        RequestStack $requestStack,
        GlobalVariablesService $globalVariablesService,
        VaultConfigService $vaultConfigService,
        ApimanTokenService $apimanTokenService,
        OAuthHelperService $oauthHelperService
    ) {
        $this->globalVariablesService = $globalVariablesService;
        $this->httpClient = $http_client;
        $this->logger = $logger;
        $this->requestStack = $requestStack;
        $this->apimanTokenService = $apimanTokenService;
        $this->vaultConfigService = $vaultConfigService;
        $this->oauthHelperService = $oauthHelperService;
    }

    public function getFlowId(): ?string
    {
        try {
            $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
            $response = $this->httpClient->request('POST', self::SECURE_LINK . $idamconfig . '/oauth2/authorize', [
                'headers' => [
                    'Accept' => self::APP_JSON,
                    'Content-Type' => self::FORM_URLENCODED,
                ],
                'form_params' => [
                    'client_id' => 'hVBu5NSpBJHJ84KF70nfQ8ZMdnQa',
                    'response_type' => 'code',
                    'redirect_uri' => 'https://cityportal.ddev.site/',
                    'scope' => 'openid internal_login',
                    'response_mode' => 'direct',
                ],
                'verify' => FALSE,
            ]);

            $result = json_decode($response->getBody()->getContents(), TRUE);
            $this->logger->notice('Authorize response: <pre>@data</pre>', ['@data' => print_r($result, TRUE)]);
            return $result['flowId'] ?? NULL;
        } catch (\Exception $e) {
            $this->logger->error('Error getting Flow ID: @msg', ['@msg' => $e->getMessage()]);
            return NULL;
        }
    }

    public function format_user_agent(string $userAgent): string
    {
        $browser = $this->oauthHelperService->detectFromRules($userAgent, [
            'Microsoft Edge' => ['Edg'],
            'Chrome'         => ['Chrome', '!Chromium'],
            'Firefox'        => ['Firefox'],
            'Safari'         => ['Safari', '!Chrome'],
            'Opera'          => ['Opera', 'OPR'],
        ], 'Unknown Browser');

        $device = $this->oauthHelperService->detectFromRules($userAgent, [
            'Desktop (Windows)' => ['Windows'],
            'Desktop (Mac)'     => ['Macintosh', 'Mac OS X'],
            'Mobile (iPhone)'   => ['iPhone'],
            'Tablet (iPad)'     => ['iPad'],
            'Mobile (Android)'  => ['Android', 'Mobile'],
            'Tablet (Android)'  => ['Android'],
            'Linux'             => ['Linux'],
        ], 'Unknown Device/OS');

        if ($browser === 'Unknown Browser' && $device === 'Unknown Device/OS') {
            return $userAgent;
        }

        return "{$browser}, {$device}";
    }

    public function authenticateUser(
        string $flow_id,
        string $email,
        string $password
    ): ?array {
        $resultData = NULL;

        try {
            $userAgent = $this->requestStack
                ->getCurrentRequest()
                ->headers
                ->get('User-Agent');

            $payload    = $this->oauthHelperService->prepareAuthPayload($flow_id, $email, $password);
            $idamconfig = $this->getIdamConfig();

            $response = $this->oauthHelperService->sendAuthenticationRequest(
                $idamconfig,
                $payload,
                $userAgent
            );

            $result = $this->oauthHelperService->parseResponse($response);

            if ($this->oauthHelperService->isAuthSuccess($result)) {
                $resultData = $this->oauthHelperService->handleAuthSuccess($result);
            } elseif ($this->oauthHelperService->isActiveSessionLimitReached($result)) {
                $resultData = $this->oauthHelperService->handleSessionLimit($result, $email);
            } else {
                $resultData = $this->oauthHelperService->handleErrorResponse($result);
            }
        } catch (\Throwable $e) {
            $this->oauthHelperService->logError($e->getMessage());
            $resultData = $this->oauthHelperService->generateErrorResponse();
        }

        return $resultData;
    }

    /**
     * Decode a JWT token payload.
     *
     * @param string $jwt
     *   The JWT token (e.g., id_token).
     *
     * @return array|null
     *   Returns the payload as an associative array, or NULL if invalid.
     */
    public function decodeJwt(string $jwt): ?array
    {
        if (!$this->oauthHelperService->isValidJwtFormat($jwt)) {
            return NULL; // Invalid token format
        }

        $payload = $this->oauthHelperService->extractPayloadFromJwt($jwt);
        return $this->oauthHelperService->decodeBase64Url($payload);
    }

    public function exchangeCodeForToken(string $code): ?array
    {
        try {
            $idamconfig = $this->getIdamConfig();
            $response = $this->sendTokenRequest($idamconfig, $code);
            return $this->oauthHelperService->parseResponse($response);
        } catch (\Exception $e) {
            $this->oauthHelperService->logError($e->getMessage());
            return NULL;
        }
    }

    private function sendTokenRequest($idamconfig, $code)
    {
        return $this->httpClient->request('POST', self::SECURE_LINK . $idamconfig . '/oauth2/token', [
            'headers' => ['Content-Type' => self::FORM_URLENCODED],
            'form_params' => [
                'client_id' => 'hVBu5NSpBJHJ84KF70nfQ8ZMdnQa',
                'grant_type' => 'authorization_code',
                'redirect_uri' => 'https://cityportal.ddev.site/',
                'code' => $code,
            ],
            'verify' => FALSE,
        ]);
    }

    private function getIdamConfig(): string
    {
        return $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
    }

    public function checkEmailExists(string $email, string $access_token, string $api_url, string $api_version): bool
    {
        try {
            $full_url = $api_url . 'tiotcitizenapp' . $api_version . 'user/details';
            $response = $this->httpClient->request("POST", $full_url, [
                'json' => ['userId' => $email],
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => self::APP_JSON,
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), TRUE);
            return !empty($data['data']);
        } catch (\Exception $e) {
            $this->logger->error('Error checking email: @msg', ['@msg' => $e->getMessage()]);
            return FALSE;
        }
    }

    public function logout(string $id_token_hint): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $cookies = $request->headers->get('cookie');

        try {
            $idamconfig = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
            $response = $this->httpClient->request('POST', self::SECURE_LINK . $idamconfig . '/oidc/logout', [
                'headers' => [
                    'Content-Type' => self::FORM_URLENCODED,
                    'Cookie' => $cookies,
                ],
                'form_params' => [
                    'response_mode' => 'direct',
                    'id_token_hint' => $id_token_hint,
                ],
                'verify' => FALSE, // 🚨 disables SSL verification
            ]);

            return [
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('OIDC logout failed: @message', ['@message' => $e->getMessage()]);
            return [
                'status' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Perform the full OAuth login flow: get flow ID, authenticate user, exchange code for token.
     */
    public function performOAuthLogin(string $email, string $password): ?array
    {
        // Step 1: Get Flow ID
        $flowId = $this->getFlowId();
        if (!$flowId) {
            throw new OAuthLoginException('Flow ID not received from OAuth server.');
        }

        // Step 2: Authenticate user with email & password
        $authResponse = $this->authenticateUser($flowId, $email, $password);
        if (empty($authResponse['success']) || empty($authResponse['code'])) {
            $msg = $authResponse['message'] ?? 'Authorization code not received.';
            throw new OAuthLoginException($msg);
        }

        // Step 3: Exchange authorization code for token
        $tokenData = $this->exchangeCodeForToken($authResponse['code']);
        if (empty($tokenData['access_token']) || empty($tokenData['id_token'])) {
            throw new OAuthLoginException('Failed to receive access or ID token.');
        }

        return $tokenData;
    }

    public function extractEmailFromJwt(string $idToken): ?string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return NULL;
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), TRUE);
        return $payload['sub'] ?? NULL;
    }

    /**
     * Validate email existence via external API.
     */
    public function validateEmail(string $email): bool
    {
        $accessToken = $this->apimanTokenService->getApimanAccessToken();
        $globals = $this->vaultConfigService->getGlobalVariables();

        $apiUrl = $globals['apiManConfig']['config']['apiUrl'] ?? '';
        $apiVersion = $globals['apiManConfig']['config']['apiVersion'] ?? '';

        return $this->checkEmailExists($email, $accessToken, $apiUrl, $apiVersion);
    }
}
