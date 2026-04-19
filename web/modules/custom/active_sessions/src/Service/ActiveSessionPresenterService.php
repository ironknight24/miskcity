<?php

namespace Drupal\active_sessions\Service;

use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Service for presenting and normalizing active session data.
 *
 * Handles session categorization, formatting, and presentation to users.
 */
class ActiveSessionPresenterService
{
    protected DateFormatterInterface $dateFormatter;

    public function __construct(DateFormatterInterface $dateFormatter)
    {
        $this->dateFormatter = $dateFormatter;
    }

    /**
     * Prepares sessions by separating current session from others.
     *
     * @param array $sessions List of session data
     * @param int|null $loginTime Current login timestamp (in seconds)
     * @param string $accessToken Access token for the current session
     *
     * @return array Array containing [current sessions, other sessions]
     */
    public function prepareSessions(array $sessions, ?int $loginTime, string $accessToken): array
    {
        // Find the session ID that matches the current login time
        $currentSessionId = $this->findClosestSessionId($sessions, $loginTime);
        $current = [];
        $others = [];

        // Categorize sessions into current and others
        foreach ($sessions as $session) {
            $normalized = $this->normalizeSession($session, $accessToken);

            if (($session['id'] ?? NULL) === $currentSessionId) {
                $current[] = $normalized;
            }
            else {
                $others[] = $normalized;
            }
        }

        return [$current, $others];
    }

    /**
     * Finds the session ID with login time closest to the provided login time.
     *
     * Compares timestamps in milliseconds to find the best match.
     *
     * @param array $sessions List of sessions with loginTime in milliseconds
     * @param int|null $loginTime Target login time in seconds
     *
     * @return string|null Session ID of the closest match, or NULL if no match found
     */
    protected function findClosestSessionId(array $sessions, ?int $loginTime): ?string
    {
        // Return early if required data is missing
        if (empty($loginTime) || empty($sessions)) {
            return NULL;
        }

        // Convert login time from seconds to milliseconds for comparison
        $targetMs = $loginTime * 1000;
        $closestId = NULL;
        $closestDiff = PHP_INT_MAX;

        // Iterate through sessions to find the closest match
        foreach ($sessions as $session) {
            if (empty($session['loginTime'])) {
                continue;
            }

            // Calculate the time difference in milliseconds
            $diff = abs($session['loginTime'] - $targetMs);
            if ($diff < $closestDiff) {
                $closestDiff = $diff;
                $closestId = $session['id'];
            }

            // If exact match found, break early for performance
            if ($diff === 0) {
                break;
            }
        }

        return $closestId;
    }

    /**
     * Normalizes session data for presentation.
     *
     * Adds formatted versions of timestamps, user agent, and access token.
     *
     * @param array $session Session data to normalize
     * @param string $accessToken Access token to attach to session
     *
     * @return array Normalized session data with formatted fields
     */
    protected function normalizeSession(array $session, string $accessToken): array
    {
        // Convert login time from milliseconds to seconds (Unix timestamp)
        $timestamp = (int) (($session['loginTime'] ?? 0) / 1000);

        // Add access token and formatted versions
        $session['accessToken'] = $accessToken;
        $session['userAgentFormatted'] = $this->formatUserAgent($session['userAgent'] ?? '');
        $session['loginTimeSeconds'] = $timestamp;
        $session['formattedLoginTime'] = $this->dateFormatter->format(
            $timestamp,
            'custom',
            'd-m-Y, h:i:s',
            'Asia/Kolkata'
        );

        return $session;
    }

    /**
     * Formats user agent string into a human-readable browser and device string.
     *
     * @param string $userAgent Raw user agent string
     *
     * @return string Formatted string like "Chrome, Desktop (Windows)" or original user agent
     */
    protected function formatUserAgent(string $userAgent): string
    {
        // Detect browser and device from user agent
        $browser = $this->detectValue($userAgent, $this->browserMap(), 'Unknown Browser');
        $device = $this->detectValue($userAgent, $this->deviceMap(), 'Unknown Device/OS');

        // If detection failed, return original user agent string
        return ($browser === 'Unknown Browser' && $device === 'Unknown Device/OS')
            ? $userAgent
            : "{$browser}, {$device}";
    }

    /**
     * Detects a value from the user agent string using pattern matching.
     *
     * @param string $userAgent User agent string to search
     * @param array $map Map of labels to patterns to match against
     * @param string $default Default value if no match found
     *
     * @return string Matched label or default value
     */
    protected function detectValue(string $userAgent, array $map, string $default): string
    {
        // Iterate through map to find matching pattern
        foreach ($map as $label => $patterns) {
            foreach ((array) $patterns as $pattern) {
                // Case-insensitive substring search
                if (stripos($userAgent, $pattern) !== FALSE) {
                    return $label;
                }
            }
        }

        return $default;
    }

    /**
     * Browser detection patterns map.
     *
     * @return array Associative array of browser names and their identifying patterns
     */
    protected function browserMap(): array
    {
        return [
            'Microsoft Edge' => ['Edg'],
            'Chrome' => ['Chrome'],
            'Firefox' => ['Firefox'],
            'Safari' => ['Safari'],
            'Opera' => ['Opera', 'OPR'],
        ];
    }

    /**
     * Device/OS detection patterns map.
     *
     * @return array Associative array of device types and their identifying patterns
     */
    protected function deviceMap(): array
    {
        return [
            'Mobile (iPhone)' => ['iPhone'],
            'Tablet (iPad)' => ['iPad'],
            'Desktop (Windows)' => ['Windows'],
            'Desktop (Mac)' => ['Macintosh', 'Mac OS X'],
            'Mobile (Android)' => ['Android Mobile'],
            'Tablet (Android)' => ['Android'],
            'Linux' => ['Linux'],
        ];
    }
}
