<?php

namespace Drupal\Tests\event_booking\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;

/**
 * Kernel tests for install and update hooks of event_booking.
 *
 * @group event_booking
 */
final class EventBookingInstallTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'event_booking',
  ];

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    if (!getenv('SIMPLETEST_DB')) {
      self::markTestSkipped('Skipping kernel tests: SIMPLETEST_DB is not configured.');
    }
  }

  public function testInstallGrantsPermissionToAuthenticated(): void {
    /** @var \Drupal\user\Entity\Role $role */
    $role = Role::load('authenticated');
    $this->assertTrue($role->hasPermission('use event booking api'));
  }

}

