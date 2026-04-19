<?php

namespace Drupal\login_logout\Service;

/**
 * Handles JWT operations for OAuth.
 */
class OAuthJwtService
{
    public function isValidJwtFormat($jwt): bool
    {
        return count(explode('.', (string) $jwt)) === 3;
    }

    public function extractPayloadFromJwt($jwt): string
    {
        return explode('.', (string) $jwt)[1] ?? '';
    }

    public function decodeBase64Url($payload)
    {
        $payload = strtr($payload, '-_', '+/');
        $mod4 = strlen($payload) % 4;
        if ($mod4) {
            $payload .= str_repeat('=', 4 - $mod4);
        }

        return json_decode(base64_decode($payload), true);
    }
}
