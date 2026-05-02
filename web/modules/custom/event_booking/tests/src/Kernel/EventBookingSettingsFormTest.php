<?php

namespace Drupal\Tests\event_booking\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel tests for EventBookingSettingsForm.
 *
 * @group event_booking
 */
final class EventBookingSettingsFormTest extends KernelTestBase {

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

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['event_booking']);
  }

  public function testAllowedVariationIdsParsingAndPersistence(): void {
    $form = $this->container->get('form_builder')->getForm('Drupal\event_booking\Form\EventBookingSettingsForm');

    $form_state = $this->container->get('form_builder')->getFormState();
    $form_state->setValues([
      'commerce_store_id' => '2',
      'order_type_id' => 'default',
      'default_variation_id' => 7,
      'max_quantity_per_request' => 500,
      'allowed_variation_ids' => "1\n2\n3\n",
      'event_node_bundle' => 'events',
      'event_ticket_variation_field' => 'field_prod_event_variation',
      'variation_event_reference_field' => '',
      'event_date_range_field' => 'field_event_date_time',
      'event_image_field' => 'field_event_image',
      'event_location_field' => 'field_event_location',
    ]);

    $form_object = $form['#form'];
    $form_object->validateForm($form, $form_state);
    $this->assertFalse($form_state->hasAnyErrors());

    $form_object->submitForm($form, $form_state);

    $config = $this->config('event_booking.settings');
    $this->assertSame([1, 2, 3], $config->get('allowed_variation_ids'));
  }

}

