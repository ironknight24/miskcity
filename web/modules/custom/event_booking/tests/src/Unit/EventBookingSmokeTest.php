<?php

namespace Drupal\Tests\event_booking\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Simple smoke test to ensure PHPUnit picks up the event_booking test suite.
 */
class EventBookingSmokeTest extends TestCase {

  public function testSuiteIsRunning(): void {
    $this->assertTrue(TRUE, 'PHPUnit is correctly running event_booking unit tests.');
  }

}

