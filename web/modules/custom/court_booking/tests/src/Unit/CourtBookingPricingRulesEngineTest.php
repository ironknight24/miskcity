<?php

namespace Drupal\Tests\court_booking\Unit;

use Drupal\court_booking\CourtBookingPricingRulesEngine;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\court_booking\CourtBookingPricingRulesEngine
 *
 * @group court_booking
 */
class CourtBookingPricingRulesEngineTest extends UnitTestCase {

  /**
   * @covers ::localDateInInclusiveRange
   */
  public function testLocalDateInInclusiveRange(): void {
    $this->assertTrue(CourtBookingPricingRulesEngine::localDateInInclusiveRange('2026-06-15', '2026-06-01', '2026-06-30'));
    $this->assertTrue(CourtBookingPricingRulesEngine::localDateInInclusiveRange('2026-06-01', '2026-06-01', '2026-06-01'));
    $this->assertFalse(CourtBookingPricingRulesEngine::localDateInInclusiveRange('2026-05-31', '2026-06-01', '2026-06-30'));
    $this->assertFalse(CourtBookingPricingRulesEngine::localDateInInclusiveRange('2026-06-15', '2026-06-20', '2026-06-10'));
    $this->assertFalse(CourtBookingPricingRulesEngine::localDateInInclusiveRange('bad', '2026-06-01', '2026-06-30'));
  }

  /**
   * @covers ::convertLegacyWindowsToRules
   */
  public function testConvertLegacyWindowsToRules(): void {
    $legacy = [
      [
        'variation_id' => 42,
        'windows' => [
          [
            'label' => 'Peak',
            'days_of_week' => [1, 2, 3, 4, 5],
            'start_hm' => '17:00',
            'end_hm' => '21:00',
            'surcharge_source' => 'peak',
          ],
        ],
      ],
    ];
    $out = CourtBookingPricingRulesEngine::convertLegacyWindowsToRules($legacy);
    $this->assertCount(1, $out);
    $this->assertSame(42, $out[0]['variation_id']);
    $this->assertCount(1, $out[0]['rules']);
    $rule = $out[0]['rules'][0];
    $this->assertSame('time_band', $rule['rule_type']);
    $this->assertSame('surcharge_field', $rule['modifier_kind']);
    $this->assertSame('peak', $rule['surcharge_field']);
    $this->assertSame('17:00', $rule['start_hm']);
    $this->assertSame('21:00', $rule['end_hm']);
  }

  /**
   * @covers ::timeBandRulesToWindowPickList
   */
  public function testTimeBandRulesToWindowPickListAddsFullRule(): void {
    $rules = [
      [
        'rule_type' => 'time_band',
        'days_of_week' => [3],
        'start_hm' => '10:00',
        'end_hm' => '12:00',
        'surcharge_field' => 'weekend',
        'modifier_kind' => 'surcharge_field',
      ],
    ];
    $windows = CourtBookingPricingRulesEngine::timeBandRulesToWindowPickList($rules);
    $this->assertArrayHasKey('_full_rule', $windows[0]);
    $this->assertSame('weekend', $windows[0]['surcharge_source']);
  }

}
