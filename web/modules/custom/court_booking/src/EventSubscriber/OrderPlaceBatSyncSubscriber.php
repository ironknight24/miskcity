<?php

namespace Drupal\court_booking\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_bat\Util\DateTimeHelper;
use Drupal\court_booking\CoachAvailabilityService;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;

/**
 * Re-syncs Commerce BAT events after checkout (safety net for missing blockouts).
 *
 * Runs after commerce_bat's OrderPlaceSubscriber. Reloads the order from storage
 * and calls commerce_bat_sync_order_events(), which recreates events from
 * order line item date fields with skip_availability.
 *
 * Also creates coach_booking_event BAT events for any coach selected on an
 * order item via field_selected_coach, preventing double-booking across courts.
 */
class OrderPlaceBatSyncSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected LoggerChannelInterface $logger,
    protected CoachAvailabilityService $coachAvailability,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Negative priority runs after Commerce BAT's subscriber (default 0).
      'commerce_order.place.post_transition'   => ['onOrderPlace', -100],
      'commerce_order.cancel.post_transition'  => ['onOrderCancel', -100],
    ];
  }

  /**
   * Mirrors paid order lines into BAT events when dates + lesson mode are set.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event): void {
    if (!function_exists('commerce_bat_sync_order_events')) {
      return;
    }
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface || !$order->id()) {
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    $fresh = $storage->load($order->id());
    if (!$fresh instanceof OrderInterface) {
      return;
    }
    $synced = commerce_bat_sync_order_events($fresh);
    if ($synced > 0) {
      $this->logger->info('Court booking: synced @n BAT order line(s) for order @id.', [
        '@n' => $synced,
        '@id' => $fresh->id(),
      ]);
    }

    $this->syncCoachEvents($fresh);
  }

  /**
   * Releases coach BAT blocking events when an order is cancelled.
   */
  public function onOrderCancel(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if (!$order instanceof OrderInterface || !$order->id()) {
      return;
    }

    $coach_deleted = $this->coachAvailability->deleteCoachBlockingEventsForOrder((int) $order->id());
    if ($coach_deleted > 0) {
      $this->logger->info('Court booking: released @n coach BAT event(s) for cancelled order @id.', [
        '@n'  => $coach_deleted,
        '@id' => $order->id(),
      ]);
    }

    $court_deleted = $this->coachAvailability->deleteCourtBlockingEventsForOrder((int) $order->id());
    if ($court_deleted > 0) {
      $this->logger->info('Court booking: released @n court BAT event(s) for cancelled order @id.', [
        '@n'  => $court_deleted,
        '@id' => $order->id(),
      ]);
    }
  }

  /**
   * Creates coach_booking_event BAT entries for any coach selected on an order item.
   *
   * Reads field_selected_coach + field_cbat_rental_date from each order item.
   * Silently skips items where those fields are absent or empty.
   */
  private function syncCoachEvents(OrderInterface $order): void {
    $date_field = 'field_cbat_rental_date';
    $coach_field = 'field_selected_coach';
    $tz = new \DateTimeZone('UTC');

    foreach ($order->getItems() as $item) {
      if (!$item->hasField($coach_field) || $item->get($coach_field)->isEmpty()) {
        continue;
      }
      if (!$item->hasField($date_field) || $item->get($date_field)->isEmpty()) {
        continue;
      }

      $coach_node = $item->get($coach_field)->entity;
      if (!$coach_node || $coach_node->get('field_coach_bat_unit')->isEmpty()) {
        continue;
      }

      $values   = $item->get($date_field)->first()->getValue();
      $start_raw = $values['value'] ?? NULL;
      $end_raw   = $values['end_value'] ?? NULL;
      if (!$start_raw || !$end_raw) {
        continue;
      }

      try {
        $start = DateTimeHelper::normalizeUtc(new \DateTimeImmutable($start_raw, $tz));
        $end   = DateTimeHelper::normalizeUtc(new \DateTimeImmutable($end_raw, $tz));
      }
      catch (\Throwable $e) {
        $this->logger->warning('Coach BAT sync: invalid dates on order item @id: @msg', [
          '@id'  => $item->id(),
          '@msg' => $e->getMessage(),
        ]);
        continue;
      }

      $bat_unit_id = (int) $coach_node->get('field_coach_bat_unit')->target_id;
      $created = $this->coachAvailability->createCoachBlockingEvent(
        $bat_unit_id,
        $start,
        $end,
        [
          'coach_name'    => $coach_node->get('field_coach_name')->value ?: $coach_node->label(),
          'order_id'      => $order->id(),
          'order_item_id' => $item->id(),
        ]
      );

      if ($created) {
        $this->logger->info('Court booking: coach BAT event created (unit @uid) for order @oid.', [
          '@uid' => $bat_unit_id,
          '@oid' => $order->id(),
        ]);
      }
    }
  }

}
