<?php

/**
 * @file
 * Redis cache backend for Docker Compose when REDIS_HOST is set.
 *
 * Requires: composer install (drupal/redis), PhpRedis in the web image, and
 * the `redis` Compose service. Enable the module: `drush en redis -y`.
 *
 * @see https://www.drupal.org/project/redis
 */

use Drupal\Core\Installer\InstallerKernel;

$redis_example = $app_root . '/modules/contrib/redis/example.services.yml';
if (!file_exists($redis_example)) {
  return;
}

if (!InstallerKernel::installationAttempted() && extension_loaded('redis')) {
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host'] = getenv('REDIS_HOST') ?: '127.0.0.1';
  $settings['redis.connection']['port'] = (int) (getenv('REDIS_PORT') ?: '6379');
  $redis_password = getenv('REDIS_PASSWORD');
  if (is_string($redis_password) && $redis_password !== '') {
    $settings['redis.connection']['password'] = $redis_password;
  }

  $settings['cache']['default'] = 'cache.backend.redis';

  $settings['redis_compress_length'] = 100;
  $settings['redis_ttl_offset'] = 3600;
  $settings['redis_invalidate_all_as_delete'] = TRUE;

  $settings['container_yamls'][] = $app_root . '/modules/contrib/redis/example.services.yml';
  $settings['container_yamls'][] = $app_root . '/modules/contrib/redis/redis.services.yml';

  $class_loader->addPsr4('Drupal\\redis\\', $app_root . '/modules/contrib/redis/src');

  $settings['bootstrap_container_definition'] = [
    'parameters' => [],
    'services' => [
      'redis.factory' => [
        'class' => 'Drupal\redis\ClientFactory',
      ],
      'cache.backend.redis' => [
        'class' => 'Drupal\redis\Cache\CacheBackendFactory',
        'arguments' => ['@redis.factory', '@cache_tags_provider.container', '@serialization.phpserialize'],
      ],
      'cache.container' => [
        'class' => '\Drupal\redis\Cache\PhpRedis',
        'factory' => ['@cache.backend.redis', 'get'],
        'arguments' => ['container'],
      ],
      'cache_tags_provider.container' => [
        'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
        'arguments' => ['@redis.factory'],
      ],
      'serialization.phpserialize' => [
        'class' => 'Drupal\Component\Serialization\PhpSerialize',
      ],
    ],
  ];
}
