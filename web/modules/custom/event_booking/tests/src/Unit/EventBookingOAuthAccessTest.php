<?php

namespace Drupal\Tests\event_booking\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\event_booking\Access\EventBookingOAuthAccess;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Cache\Context\CacheContextsManager;

/**
 * Unit tests for EventBookingOAuthAccess.
 *
 * @group event_booking
 */
class EventBookingOAuthAccessTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  public function testBookingApiAccess(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')
      ->willReturnMap([
        ['use event booking api', TRUE],
      ]);

    $result = EventBookingOAuthAccess::bookingApi($account);
    $this->assertTrue($result->isAllowed());
  }

  public function testUnifiedBookingsAccessWithEitherPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')
      ->willReturnMap([
        ['use event booking api', FALSE],
        ['use court booking add', TRUE],
      ]);

    $result = EventBookingOAuthAccess::unifiedBookingsApi($account);
    $this->assertTrue($result->isAllowed());
  }

}

