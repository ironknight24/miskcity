<?php

namespace Drupal\login_logout\Service;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\global_module\Service\VaultConfigService;

class OAuthHelperService
{

    public const SECURE_LINK = 'https://';
    public const APP_JSON = 'application/json';
    protected $logger;
    protected $httpClient;
    protected $vaultConfigService;
    protected $jwtService;
    protected $sessionFormatter;

    public function __construct(
        ClientInterface $http_client,
        LoggerInterface $logger,
        VaultConfigService $vaultConfigService,
        OAuthJwtService $jwt_service,
        OAuthSessionFormatterService $session_formatter
    ) {
        $this->httpClient = $http_client;
        $this->logger = $logger;
        $this->vaultConfigService = $vaultConfigService;
        $this->jwtService = $jwt_service;
        $this->sessionFormatter = $session_formatter;
    }

    public function detectFromRules(string $agent, array $rules, string $default): string
    {
        foreach ($rules as $label => $conditions) {
            if ($this->matchesConditions($agent, (array) $conditions)) {
                return $label;
            }
        }

        return $default;
    }

    public function matchesConditions(string $agent, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (!$this->matchesCondition($agent, $condition)) {
                return FALSE;
            }
        }

        return TRUE;
    }

    protected function matchesCondition(string $agent, string $condition): bool
    {
        $negate = $condition[0] === '!';
        $token  = ltrim($condition, '!');
        $found = stripos($agent, $token) !== FALSE;
        return $negate ? !$found : $found;
    }

    public function prepareAuthPayload($flow_id, $email, $password)
    {
        $authenticatorId = $this->vaultConfigService->getGlobalVariables()['applicationConfig']['config']['authenticatorId'];
        return [
            "flowId" => $flow_id,
            "selectedAuthenticator" => [
                "authenticatorId" => $authenticatorId,
                "params" => ["username" => $email, "password" => $password],
            ],
        ];
    }

    public function sendAuthenticationRequest($idamconfig, $payload, $userAgent)
    {
        return $this->httpClient->request('POST', self::SECURE_LINK . $idamconfig . '/oauth2/authn', [
            'headers' => [
                'Accept' => self::APP_JSON,
                'Content-Type' => self::APP_JSON,
                'User-Agent' => $userAgent,
            ],
            'json' => $payload,
            'verify' => FALSE,
        ]);
    }

    public function parseResponse($response)
    {
        return json_decode($response->getBody()->getContents(), TRUE);
    }

    public function isAuthSuccess($result)
    {
        return !empty($result['authData']['code']);
    }

    public function handleAuthSuccess($result)
    {
        return ['success' => TRUE, 'code' => $result['authData']['code'], 'message' => NULL];
    }

    public function isActiveSessionLimitReached($result)
    {
        $authenticator = $result['nextStep']['authenticators'][0]['authenticator'] ?? '';
        return $authenticator === 'Active Sessions Limit';
    }

    public function handleSessionLimit($result, $email)
    {
        $metadata = $result['nextStep']['authenticators'][0]['metadata']['additionalData'] ?? [];
        $maxSessions = $metadata['MaxSessionCount'] ?? 'unknown';
        $sessions = json_decode($metadata['sessions'] ?? '[]', TRUE);
        
        $this->logActiveSessions($email, $sessions);

        return [
            'success' => FALSE,
            'code' => NULL,
            'message' => "You have reached the maximum active sessions ($maxSessions).",
        ];
    }

    protected function logActiveSessions(string $email, array $sessions): void
    {
        $this->logger->notice('Active sessions for user @email: @sessions', [
            '@email' => $email,
            '@sessions' => $this->sessionFormatter->formatSessions($sessions),
        ]);
    }

    public function handleErrorResponse($result)
    {
        $message = $result['nextStep']['messages'][0]['message'] ?? 'Authentication failed. Please try again.';
        return [
            'success' => FALSE,
            'code' => NULL,
            'message' => $message,
        ];
    }

    public function logError($message)
    {
        $this->logger->error('Error authenticating user: @msg', ['@msg' => $message]);
    }

    public function generateErrorResponse()
    {
        return [
            'success' => FALSE,
            'code' => NULL,
            'message' => 'An error occurred during authentication. Please try again later.',
        ];
    }

    public function isValidJwtFormat($jwt)
    {
        return $this->jwtService->isValidJwtFormat($jwt);
    }

    public function extractPayloadFromJwt($jwt)
    {
        return $this->jwtService->extractPayloadFromJwt($jwt);
    }

    public function decodeBase64Url($payload)
    {
        return $this->jwtService->decodeBase64Url($payload);
    }
}
