<?php

namespace Drupal\Tests\court_booking\Unit;

use Drupal\court_booking\CourtBookingPriceResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\court_booking\CourtBookingPriceResolver
 *
 * @group court_booking
 */
class CourtBookingPriceResolverTest extends UnitTestCase {

  /**
   * @covers ::pickWinningWindowFromList
   */
  public function testPickWinningWindowEmpty(): void {
    $this->assertNull(CourtBookingPriceResolver::pickWinningWindowFromList(3, 600, []));
  }

  /**
   * @covers ::pickWinningWindowFromList
   */
  public function testPickWinningWindowHalfOpenEndExclusive(): void {
    $windows = [
      [
        'label' => 'Peak',
        'days_of_week' => [1, 2, 3, 4, 5],
        'start_hm' => '17:00',
        'end_hm' => '22:00',
        'surcharge_source' => 'peak',
      ],
    ];
    $this->assertNull(CourtBookingPriceResolver::pickWinningWindowFromList(3, 22 * 60, $windows));
    $picked = CourtBookingPriceResolver::pickWinningWindowFromList(3, 21 * 60 + 59, $windows);
    $this->assertNotNull($picked);
    $this->assertSame('peak', $picked['surcharge_source']);
    $pickedStart = CourtBookingPriceResolver::pickWinningWindowFromList(3, 17 * 60, $windows);
    $this->assertNotNull($pickedStart);
  }

  /**
   * Fewer-day scope wins over all-days when both match.
   *
   * @covers ::pickWinningWindowFromList
   */
  public function testSpecificWeekendBeatsAllDays(): void {
    $windows = [
      [
        'label' => 'All week',
        'days_of_week' => [],
        'start_hm' => '17:00',
        'end_hm' => '22:00',
        'surcharge_source' => 'peak',
      ],
      [
        'label' => 'Weekend',
        'days_of_week' => [6, 7],
        'start_hm' => '17:00',
        'end_hm' => '22:00',
        'surcharge_source' => 'weekend',
      ],
    ];
    $picked = CourtBookingPriceResolver::pickWinningWindowFromList(6, 18 * 60, $windows);
    $this->assertNotNull($picked);
    $this->assertSame('weekend', $picked['surcharge_source']);
  }

  /**
   * Narrower time window wins when day scope size ties.
   *
   * @covers ::pickWinningWindowFromList
   */
  public function testNarrowerWindowWinsSameDays(): void {
    $windows = [
      [
        'label' => 'Wide',
        'days_of_week' => [1],
        'start_hm' => '08:00',
        'end_hm' => '20:00',
        'surcharge_source' => 'peak',
      ],
      [
        'label' => 'Narrow',
        'days_of_week' => [1],
        'start_hm' => '12:00',
        'end_hm' => '14:00',
        'surcharge_source' => 'weekend',
      ],
    ];
    $picked = CourtBookingPriceResolver::pickWinningWindowFromList(1, 13 * 60, $windows);
    $this->assertNotNull($picked);
    $this->assertSame('weekend', $picked['surcharge_source']);
  }

  /**
   * @covers ::hmToMinutes
   */
  public function testHmToMinutes(): void {
    $this->assertSame(0, CourtBookingPriceResolver::hmToMinutes('00:00'));
    $this->assertSame(90, CourtBookingPriceResolver::hmToMinutes('01:30'));
    $this->assertNull(CourtBookingPriceResolver::hmToMinutes('24:00'));
    $this->assertNull(CourtBookingPriceResolver::hmToMinutes('9:00'));
  }

  /**
   * Windows may use surcharge_field (new) or surcharge_source (legacy tests).
   *
   * @covers ::pickWinningWindowFromList
   */
  public function testPickWinningWindowWithSurchargeFieldKey(): void {
    $windows = [
      [
        'label' => 'X',
        'days_of_week' => [1],
        'start_hm' => '09:00',
        'end_hm' => '12:00',
        'surcharge_field' => 'peak',
      ],
    ];
    $picked = CourtBookingPriceResolver::pickWinningWindowFromList(1, 10 * 60, $windows);
    $this->assertNotNull($picked);
    $this->assertSame('peak', $picked['surcharge_source']);
  }

}
