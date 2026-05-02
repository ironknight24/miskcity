<?php

/**
 * @file
 * Example site settings for Docker Compose — copy to `settings.php`.
 *
 *   cp web/sites/default/example.settings.docker.php web/sites/default/settings.php
 *
 * `settings.php` is gitignored; keep secrets out of git.
 *
 * @see docker-compose.yml `web` environment: DRUPAL_DB_*, REDIS_*.
 */

$app_root = $app_root ?? dirname(__DIR__, 2);
$site_path = $site_path ?? 'sites/default';

/**
 * Load core defaults first (this sets $databases = [] among other defaults).
 */
include $app_root . '/' . $site_path . '/default.settings.php';

/**
 * Override database for Docker Compose (`db` hostname inside the network).
 */
$databases['default']['default'] = [
  'driver' => 'mysql',
  'database' => getenv('DRUPAL_DB_NAME') ?: 'drupal',
  'username' => getenv('DRUPAL_DB_USER') ?: 'drupal',
  'password' => getenv('DRUPAL_DB_PASSWORD') ?: 'drupal',
  'host' => getenv('DRUPAL_DB_HOST') ?: 'db',
  'port' => getenv('DRUPAL_DB_PORT') ?: '3306',
  'prefix' => '',
];

$settings['hash_salt'] = 'miskcity-local-docker-hash-salt-change-for-production';

/**
 * Redis application cache (Compose service `redis` when REDIS_HOST is set).
 *
 * Merge this block into an existing settings.php if you maintain one by hand.
 */
if (getenv('REDIS_HOST')) {
  $redis_settings = $app_root . '/' . $site_path . '/redis.settings.php';
  if (is_readable($redis_settings)) {
    include $redis_settings;
  }
}
