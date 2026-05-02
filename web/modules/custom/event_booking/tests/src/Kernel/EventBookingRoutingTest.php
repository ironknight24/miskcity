<?php

namespace Drupal\Tests\event_booking\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for routing and permissions of event_booking.
 *
 * @group event_booking
 */
final class EventBookingRoutingTest extends KernelTestBase {

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

  public function testRoutesExistWithExpectedDefaults(): void {
    $provider = $this->container->get('router.route_provider');

    $route = $provider->getRouteByName('event_booking.rest_v1_portal_verify');
    $this->assertSame('/rest/v1/event-booking/portal-user/verify', $route->getPath());
    $this->assertSame('\Drupal\event_booking\Controller\EventBookingRestController::verifyPortalUser', $route->getDefault('_controller'));
    $this->assertSame(['court_booking_bearer'], $route->getOption('_auth'));
  }

  public function testPermissionsAreRegistered(): void {
    $permissions = array_keys(\Drupal::service('user.permissions')->getPermissions());
    $this->assertContains('use event booking api', $permissions);
    $this->assertContains('administer event booking', $permissions);
  }

}

