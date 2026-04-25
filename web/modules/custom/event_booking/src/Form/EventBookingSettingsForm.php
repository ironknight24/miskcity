<?php

namespace Drupal\event_booking\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for event booking REST API.
 */
final class EventBookingSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'event_booking_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['event_booking.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('event_booking.settings');

    $form['commerce_store_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Commerce store ID'),
      '#description' => $this->t('Numeric store ID for Event Store (e.g. 2).'),
      '#default_value' => $config->get('commerce_store_id'),
      '#required' => TRUE,
    ];
    $form['order_type_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order type ID'),
      '#default_value' => $config->get('order_type_id'),
      '#required' => TRUE,
    ];
    $form['default_variation_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Default ticket variation ID'),
      '#min' => 1,
      '#default_value' => $config->get('default_variation_id'),
    ];
    $form['max_quantity_per_request'] = [
      '#type' => 'number',
      '#title' => $this->t('Max tickets per request'),
      '#min' => 1,
      '#default_value' => $config->get('max_quantity_per_request'),
      '#required' => TRUE,
    ];
    $form['allowed_variation_ids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed variation IDs (optional)'),
      '#description' => $this->t('One integer per line. Leave empty to allow any purchasable variation in the event store.'),
      '#default_value' => implode("\n", (array) $config->get('allowed_variation_ids')),
    ];
    $form['event_node_bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event content type (bundle)'),
      '#description' => $this->t('Machine name of the Events node type (e.g. events). Used to find the event that references a purchased ticket variation on receipts.'),
      '#default_value' => $config->get('event_node_bundle') ?: 'events',
      '#required' => TRUE,
    ];
    $form['event_ticket_variation_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event node field: ticket variation'),
      '#description' => $this->t('Machine name of the entity reference on the <em>event</em> node pointing at the Commerce product variation (e.g. field_prod_event_variation).'),
      '#default_value' => $config->get('event_ticket_variation_field') ?: 'field_prod_event_variation',
      '#required' => TRUE,
    ];
    $form['variation_event_reference_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Legacy fallback: variation field referencing event'),
      '#description' => $this->t('Optional. If receipts find no event via the event→variation field above, try this field on the variation (old model). Leave empty when fully migrated.'),
      '#default_value' => $config->get('variation_event_reference_field'),
      '#required' => FALSE,
    ];
    $form['event_date_range_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event node field: date range'),
      '#default_value' => $config->get('event_date_range_field'),
      '#required' => TRUE,
    ];
    $form['event_image_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event node field: image'),
      '#default_value' => $config->get('event_image_field'),
      '#required' => TRUE,
    ];
    $form['event_location_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event node field: location'),
      '#default_value' => $config->get('event_location_field'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $allowed_raw = $form_state->getValue('allowed_variation_ids');
    $allowed = [];
    if (is_string($allowed_raw) && trim($allowed_raw) !== '') {
      foreach (preg_split('/\R/', $allowed_raw) as $line) {
        $line = trim($line);
        if ($line !== '' && ctype_digit($line)) {
          $allowed[] = (int) $line;
        }
      }
    }

    $this->config('event_booking.settings')
      ->set('commerce_store_id', trim((string) $form_state->getValue('commerce_store_id')))
      ->set('order_type_id', trim((string) $form_state->getValue('order_type_id')))
      ->set('default_variation_id', (int) $form_state->getValue('default_variation_id'))
      ->set('max_quantity_per_request', (int) $form_state->getValue('max_quantity_per_request'))
      ->set('allowed_variation_ids', $allowed)
      ->set('event_node_bundle', trim((string) $form_state->getValue('event_node_bundle')))
      ->set('event_ticket_variation_field', trim((string) $form_state->getValue('event_ticket_variation_field')))
      ->set('variation_event_reference_field', trim((string) $form_state->getValue('variation_event_reference_field')))
      ->set('event_date_range_field', trim((string) $form_state->getValue('event_date_range_field')))
      ->set('event_image_field', trim((string) $form_state->getValue('event_image_field')))
      ->set('event_location_field', trim((string) $form_state->getValue('event_location_field')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
