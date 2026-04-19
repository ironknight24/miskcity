<?php

namespace Drupal\global_module\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * VaultConfigService class.
 *
 * Manages retrieval and caching of application configuration from HashiCorp Vault.
 * Handles HTTP communication with Vault, data normalization, cache management,
 * and lock-based concurrency control to prevent thundering herd during cache misses.
 */
class VaultConfigService
{

    // Cache key identifier for storing Vault configuration data
    public const CACHE_ID = 'vault_config_data';

    // Cache time-to-live in seconds (1 hour = 3600 seconds)
    public const CACHE_TTL = 3600;

    // Content type constant for JSON HTTP communication
    public const APP_JSON = 'application/json';

    /**
     * Constructs the Vault configuration service with required dependencies.
     *
     * @param \GuzzleHttp\ClientInterface $httpClient
     *   HTTP client for making requests to Vault service.
     * @param \Drupal\Core\Cache\CacheBackendInterface $cache
     *   Cache backend for storing and retrieving configuration data.
     * @param \Drupal\Core\Lock\LockBackendInterface $lock
     *   Lock backend for preventing concurrent Vault requests during cache miss.
     * @param \Psr\Log\LoggerInterface $logger
     *   Logger for recording errors and debugging information.
     */
    public function __construct(
        protected ClientInterface $httpClient,
        protected CacheBackendInterface $cache,
        protected LockBackendInterface $lock,
        protected LoggerInterface $logger
    ) {}

    /**
     * Fetch global configuration from Vault.
     *
     * Retrieves cached configuration if available and not expired.
     * On cache miss, acquires a distributed lock to prevent multiple
     * concurrent requests to Vault (thundering herd prevention).
     * Returns cached copy while waiting for lock if already held.
     *
     * @return ?array
     *   Configuration array from Vault or cache, NULL if retrieval failed.
     */
    public function getGlobalVariables(): ?array
    {
        // Check cache first - returns immediately if fresh data available
        if ($cached = $this->getFromCache()) {
            return $cached;
        }

        // Attempt to acquire lock to prevent multiple Vault requests
        // If lock cannot be acquired, another process is already fetching
        if (!$this->acquireLock()) {
            // Return cached copy while other process fetches (cache may be stale but avoids thundering herd)
            return $this->getFromCache();
        }

        // Lock acquired - fetch from Vault and cache result
        return $this->fetchAndCacheVaultData();
    }

    /**
     * Fetches configuration from Vault and stores it in cache.
     *
     * Orchestrates the workflow of fetching Vault data, normalizing format,
     * storing in cache, and releasing the acquired lock. Uses try/finally
     * to ensure lock is always released even if an error occurs.
     *
     * @return ?array
     *   Normalized configuration array from Vault, NULL on failure.
     */
    private function fetchAndCacheVaultData(): ?array
    {
        try {
            // Make HTTP request to Vault and extract configuration data
            $vaultData = $this->fetchFromVault();

            // Return NULL if Vault request failed or returned no data
            if (!$vaultData) {
                return NULL;
            }

            // Transform Vault response into normalized structure for application use
            $vaultData = $this->normalizeVaultData($vaultData);

            // Store normalized data in cache with TTL expiration
            $this->storeInCache($vaultData);

            return $vaultData;
        } catch (\Throwable $e) {
            // Log any exceptions (network errors, JSON parsing, etc.)
            $this->logError($e);
            return NULL;
        } finally {
            // Always release lock, even if error occurred above
            // Prevents lock from remaining held and blocking other requests
            $this->releaseLock();
        }
    }

    /* ====================================================================
     * HELPER METHODS (reduce cognitive complexity)
     *
     * Private utility methods organized by responsibility:
     * - Cache management (get/set)
     * - Lock management (acquire/release)
     * - Vault communication (fetch/normalize)
     * - Logging
     * ==================================================================== */

    /**
     * Retrieves cached configuration data if available.
     *
     * @return ?array
     *   Cached configuration array or NULL if not found in cache.
     */
    private function getFromCache(): ?array
    {
        return $this->cache->get(self::CACHE_ID)->data ?? NULL;
    }

    /**
     * Stores configuration data in cache with expiration time.
     *
     * @param array $data
     *   Configuration data to store in cache.
     */
    private function storeInCache(array $data): void
    {
        // Set cache with TTL: current time + cache lifetime
        // Cache backend will automatically purge data after expiration
        $this->cache->set(
            self::CACHE_ID,
            $data,
            time() + self::CACHE_TTL
        );
    }

    /**
     * Attempts to acquire a distributed lock for Vault fetching.
     *
     * Prevents multiple concurrent requests to Vault when cache expires.
     * If lock cannot be acquired immediately, sleeps briefly and retries once
     * to avoid busy-waiting while another process fetches data.
     *
     * @return bool
     *   TRUE if lock acquired, FALSE if another process already holds it.
     */
    private function acquireLock(): bool
    {
        // Attempt to acquire lock with 30-second timeout
        // Returns TRUE immediately if lock acquired, FALSE if held by another process
        if ($this->lock->acquire(self::CACHE_ID, 30)) {
            return TRUE;
        }

        // Another process holds the lock - sleep 0.1 seconds to avoid busy waiting
        usleep(100000);

        // Return FALSE to indicate lock could not be acquired
        return FALSE;
    }

    /**
     * Releases the distributed lock after Vault fetching completes.
     *
     * Allows other waiting processes to acquire lock and proceed.
     */
    private function releaseLock(): void
    {
        $this->lock->release(self::CACHE_ID);
    }

    /**
     * Fetches configuration data from HashiCorp Vault service.
     *
     * Makes authenticated HTTP GET request to Vault using token stored in settings,
     * extracts and decodes JSON response, and returns the 'data' field.
     *
     * @return ?array
     *   Configuration data from Vault response, NULL if fetch failed.
     */
    private function fetchFromVault(): ?array
    {
        // Retrieve Vault URL and token from Drupal settings (settings.php)
        $vaultUrl = Settings::get('vault_url');
        $vaultToken = Settings::get('vault_token');

        // Check if required Vault credentials are configured
        if (!$vaultUrl || !$vaultToken) {
            $this->logger->error('Vault URL or token missing in settings.php');
            return NULL;
        }

        // Make HTTP GET request to Vault with authentication headers
        $response = $this->httpClient->request('GET', $vaultUrl, [
            'headers' => [
                'Content-Type' => self::APP_JSON,
                'X-Vault-Token' => $vaultToken,
            ],
        ]);

        // Decode JSON response body into associative array
        $payload = json_decode($response->getBody()->getContents(), TRUE);

        // Extract 'data' field from Vault response, NULL if not present
        return $payload['data'] ?? NULL;
    }

    /**
     * Normalizes Vault response data for use by the application.
     *
     * Extracts nested configuration values and adds convenience properties
     * at the top level for easy application access.
     *
     * @param array $vaultData
     *   Raw Vault response data to normalize.
     *
     * @return array
     *   Normalized data with convenience properties added.
     */
    private function normalizeVaultData(array $vaultData): array
    {
        // Extract config object nested inside applicationConfig
        $config = $vaultData['applicationConfig']['config'] ?? [];

        // Add webportal URL to root level for convenience access
        $vaultData['webportalUrl'] = $config['webportalUrl'] ?? '';

        // Add site URL to root level for convenience access
        $vaultData['siteUrl'] = $config['siteUrl'] ?? '';

        return $vaultData;
    }

    /**
     * Logs error information from exceptions.
     *
     * Formats exception message for logging with context replacement.
     *
     * @param \Throwable $e
     *   The throwable exception to log.
     */
    private function logError(\Throwable $e): void
    {
        // Log error with exception message substituted into message placeholder
        $this->logger->error('Vault fetch failed: @message', [
            '@message' => $e->getMessage(),
        ]);
    }
}
