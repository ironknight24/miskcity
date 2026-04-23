<?php

namespace Drupal\custom_profile\Service;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApiHttpClientService;

class PasswordChangeService
{
    public const SECURE_LINK = "https://";

    protected $globalVariables;
    protected $logger;
    protected $currentUser;
    protected $session;
    protected $vaultConfigService;
    protected $apiHttpClientService;

    public function __construct(
        GlobalVariablesService $globalVariables,
        LoggerChannelFactoryInterface $loggerFactory,
        AccountProxyInterface $currentUser,
        SessionInterface $session,
        VaultConfigService $vaultConfigService,
        ApiHttpClientService $apiHttpClientService
    ) {
        $this->globalVariables = $globalVariables;
        $this->logger = $loggerFactory->get('change_password');
        $this->currentUser = $currentUser;
        $this->session = $session;
        $this->vaultConfigService = $vaultConfigService;
        $this->apiHttpClientService = $apiHttpClientService;
    }

    public function changePassword(string $oldPass, string $newPass, string $confirmPass): array
    {
        $result = [
            'status' => FALSE,
            'message' => 'Password not updated!',
        ];

        try {
            $mismatchMessage = $this->getPasswordMismatchMessage($newPass, $confirmPass);
            if ($mismatchMessage !== NULL) {
                $result['message'] = $mismatchMessage;
            }
            else {
                $email = $this->currentUser->getEmail();
                $idamconfig = $this->getIdamConfig();
                $idamUserId = $this->getScimUserId($email, $idamconfig);

                if ($idamUserId === NULL) {
                    $result['message'] = 'User not found in SCIM.';
                }
                elseif (!$this->isOldPasswordValid($email, $oldPass, $idamconfig)) {
                    $result['message'] = 'Old password not matching!';
                }
                else {
                    $resPass = $this->apiHttpClientService->postIdamAuth(
                        self::SECURE_LINK . $idamconfig . '/scim2/Users/' . $idamUserId,
                        $this->buildPasswordUpdatePayload($newPass),
                        'PATCH'
                    );

                    $result['message'] = $this->resolvePasswordChangeMessage($resPass, $email, $result['message']);
                    if (empty($resPass['error'])
                        && !empty($resPass['emails'][0])
                        && $resPass['emails'][0] === $email) {
                        $result['status'] = TRUE;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Exception during password change: @msg',
                ['@msg' => $e->getMessage()]
            );
            $result['message'] = 'Unexpected error occurred.';
        }

        return $result;
    }

    protected function getPasswordMismatchMessage(string $newPass, string $confirmPass): ?string
    {
        return $newPass !== $confirmPass
            ? 'New password and confirm password do not match.'
            : NULL;
    }

    protected function getIdamConfig(): string
    {
        return $this->vaultConfigService
            ->getGlobalVariables()['applicationConfig']['config']['idamconfig'];
    }

    protected function getScimUserId(string $email, string $idamconfig): ?string
    {
        $url = self::SECURE_LINK . $idamconfig . '/scim2/Users?filter='
            . urlencode("emails eq \"$email\"");
        $responseData = $this->apiHttpClientService->getApi($url);
        $idamUserId = $responseData['Resources'][0]['id'] ?? NULL;

        if ($idamUserId === NULL) {
            $this->logger->error('User ID not found for email: @mail', ['@mail' => $email]);
        }

        return $idamUserId;
    }

    protected function isOldPasswordValid(string $email, string $oldPass, string $idamconfig): bool
    {
        $payloadOld = [
            'grant_type' => 'password',
            'password' => $oldPass,
            'client_id' => 'hVBu5NSpBJHJ84KF70nfQ8ZMdnQa',
            'username' => $email,
        ];

        $resOld = $this->apiHttpClientService->postIdam(
            self::SECURE_LINK . $idamconfig . '/oauth2/token/',
            $payloadOld
        );

        return !empty($resOld['access_token']);
    }

    protected function buildPasswordUpdatePayload(string $newPass): array
    {
        return [
            'schemas' => [
                'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User',
            ],
            'Operations' => [[
                'op' => 'replace',
                'path' => 'password',
                'value' => $newPass,
            ]],
        ];
    }

    protected function resolvePasswordChangeMessage(array $resPass, string $email, string $defaultMessage): string
    {
        $message = $defaultMessage;

        if (!empty($resPass['error'])) {
            $message = $resPass['details']['detail'] ?? 'Password update failed';
        }
        elseif (!empty($resPass['emails'][0]) && $resPass['emails'][0] === $email) {
            $message = 'Password changed successfully. Please log in again.';
        }
        elseif (!empty($resPass['detail']) && str_contains(strtolower($resPass['detail']), 'password history')) {
            $message = 'The password you are trying to use was already used in your last 3 password changes. Please choose a completely new password.';
        }
        elseif (!empty($resPass['detail'])) {
            $message = $resPass['detail'];
        }

        return $message;
    }
}
