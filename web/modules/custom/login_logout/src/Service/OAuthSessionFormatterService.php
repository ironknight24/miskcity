<?php

namespace Drupal\login_logout\Service;

/**
 * Formats session information for display/logging.
 */
class OAuthSessionFormatterService
{
    public function formatSessions(array $sessions): string
    {
        $sessionList = '';
        foreach ($sessions as $s) {
            $sessionList .= $this->formatSessionEntry($s);
        }
        return $sessionList;
    }

    protected function formatSessionEntry(array $s): string
    {
        $date = date('Y-m-d H:i:s', ($s['lastAccessTime'] ?? 0) / 1000);
        return "- Browser: " . ($s['browser'] ?? 'Unknown') .
               ", Device: " . ($s['device'] ?? 'Unknown') .
               ", Last Active: {$date}\n";
    }
}
