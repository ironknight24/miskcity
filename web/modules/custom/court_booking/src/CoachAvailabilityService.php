<?php

namespace Drupal\court_booking;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Checks coach availability by querying their BAT unit for overlapping events.
 */
final class CoachAvailabilityService {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Returns all published coaches configured for a court node (no time filter).
   *
   * Use this for static listings where no booking window is known yet (e.g.
   * the sports bootstrap / discovery endpoint).
   *
   * @param int $court_nid
   *   Published court node ID.
   *
   * @return array<int, array{id: int, name: string, price: int, photo_url: string, category: string, bio: string}>
   */
  public function getCoachListForCourt(int $court_nid): array {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $court = $node_storage->load($court_nid);

    if (!$court || $court->bundle() !== 'court' || $court->get('field_court_coaches')->isEmpty()) {
      return [];
    }

    $coaches = [];
    foreach ($court->get('field_court_coaches')->referencedEntities() as $coach) {
      if ($coach->bundle() !== 'coach' || !$coach->isPublished()) {
        continue;
      }
      $coaches[] = [
        'id'        => (int) $coach->id(),
        'name'      => $coach->get('field_coach_name')->value ?: $coach->label(),
        'price'     => (int) $coach->get('field_coach_price')->value,
        'photo_url' => $this->resolvePhotoUrl($coach),
        'category'  => $coach->get('field_coach_category')->isEmpty()
          ? ''
          : ($coach->get('field_coach_category')->entity?->label() ?? ''),
        'bio'       => $coach->get('field_coach_bio')->isEmpty()
          ? ''
          : ($coach->get('field_coach_bio')->value ?? ''),
      ];
    }

    return $coaches;
  }

  /**
   * Returns coaches available for a court node during a UTC time window.
   *
   * A coach is considered available if they are assigned to the court and
   * have no coach_booking_event overlapping the requested [start, end) range.
   *
   * @param int $court_nid
   *   Published court node ID.
   * @param \DateTimeImmutable $start
   *   Slot start in UTC.
   * @param \DateTimeImmutable $end
   *   Slot end in UTC.
   *
   * @return array<int, array{id: int, name: string, price: int, photo_url: string, category: string, bio: string}>
   *   Available coach data as a flat list.
   */
  public function getAvailableCoaches(int $court_nid, \DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $court = $node_storage->load($court_nid);

    if (!$court || $court->bundle() !== 'court' || $court->get('field_court_coaches')->isEmpty()) {
      return [];
    }

    $available = [];
    foreach ($court->get('field_court_coaches')->referencedEntities() as $coach) {
      if ($coach->bundle() !== 'coach' || !$coach->isPublished()) {
        continue;
      }
      if ($coach->get('field_coach_bat_unit')->isEmpty()) {
        continue;
      }

      $bat_unit_id = (int) $coach->get('field_coach_bat_unit')->target_id;
      if (!$this->isCoachUnitAvailable($bat_unit_id, $start, $end)) {
        continue;
      }

      $available[] = [
        'id'       => (int) $coach->id(),
        'name'     => $coach->get('field_coach_name')->value ?: $coach->label(),
        'price'    => (int) $coach->get('field_coach_price')->value,
        'photo_url' => $this->resolvePhotoUrl($coach),
        'category' => $coach->get('field_coach_category')->isEmpty()
          ? ''
          : ($coach->get('field_coach_category')->entity?->label() ?? ''),
        'bio'      => $coach->get('field_coach_bio')->isEmpty()
          ? ''
          : ($coach->get('field_coach_bio')->value ?? ''),
      ];
    }

    return $available;
  }

  /**
   * Returns TRUE when no coach_booking_event overlaps the requested window.
   *
   * Overlap: existing.start < requested.end AND existing.end > requested.start
   */
  public function isCoachUnitAvailable(int $bat_unit_id, \DateTimeImmutable $start, \DateTimeImmutable $end): bool {
    $count = (int) $this->entityTypeManager
      ->getStorage('bat_event')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'coach_booking_event')
      ->condition('event_bat_unit_reference', $bat_unit_id)
      ->condition('event_dates.value', $end->format('Y-m-d\TH:i:s'), '<')
      ->condition('event_dates.end_value', $start->format('Y-m-d\TH:i:s'), '>')
      ->count()
      ->execute();

    return $count === 0;
  }

  /**
   * Deletes all coach_booking_event BAT entities linked to a given order ID.
   *
   * Called when an order is cancelled so the coach becomes re-bookable.
   *
   * @param int $order_id
   *   The commerce_order entity ID being cancelled.
   *
   * @return int
   *   Number of BAT events deleted.
   */
  /**
   * Deletes all court lesson_event BAT entities linked to a given order ID.
   *
   * Commerce BAT's own cancel subscriber does not fire when state is
   * force-set (bypassing the state machine). This method explicitly removes
   * the order_booking lesson_events so the court slot becomes available again.
   *
   * @param int $order_id
   *   The commerce_order entity ID being cancelled.
   *
   * @return int
   *   Number of BAT events deleted.
   */
  public function deleteCourtBlockingEventsForOrder(int $order_id): int {
    if ($order_id <= 0) {
      return 0;
    }

    $event_storage = $this->entityTypeManager->getStorage('bat_event');
    $count = 0;

    try {
      $ids = (array) $event_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_order_ref', $order_id)
        ->execute();

      foreach ($event_storage->loadMultiple($ids) as $bat_event) {
        // Only remove order-created events, never admin blockouts.
        $source = $bat_event->hasField('field_cbat_source')
          ? (string) $bat_event->get('field_cbat_source')->value
          : 'order_booking';
        if ($source !== 'order_booking') {
          continue;
        }
        try {
          $bat_event->delete();
          $count++;
        }
        catch (\Throwable $e) {
          \Drupal::logger('court_booking')->error(
            'Failed to delete court BAT event @eid for order @oid: @msg',
            ['@eid' => $bat_event->id(), '@oid' => $order_id, '@msg' => $e->getMessage()]
          );
        }
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('court_booking')->error(
        'Court BAT event query failed for order @oid: @msg',
        ['@oid' => $order_id, '@msg' => $e->getMessage()]
      );
    }

    return $count;
  }

  /**
   * Deletes all coach_booking_event BAT entities linked to a given order ID.
   *
   * Queries by coach BAT unit ID + time slot overlap (derived from each order
   * item's field_selected_coach and field_cbat_rental_date). This avoids
   * relying on reference fields that may not exist on the bat_event bundle.
   *
   * @param int $order_id
   *   The commerce_order entity ID being cancelled.
   *
   * @return int
   *   Number of BAT events deleted.
   */
  public function deleteCoachBlockingEventsForOrder(int $order_id): int {
    if ($order_id <= 0) {
      return 0;
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    if (!$order) {
      return 0;
    }

    $event_storage = $this->entityTypeManager->getStorage('bat_event');
    $count = 0;

    foreach ($order->getItems() as $item) {
      // Skip items without a coach or rental date.
      if (!$item->hasField('field_selected_coach') || $item->get('field_selected_coach')->isEmpty()) {
        continue;
      }
      if (!$item->hasField('field_cbat_rental_date') || $item->get('field_cbat_rental_date')->isEmpty()) {
        continue;
      }

      $coach = $item->get('field_selected_coach')->entity;
      if (!$coach || $coach->get('field_coach_bat_unit')->isEmpty()) {
        continue;
      }
      $bat_unit_id = (int) $coach->get('field_coach_bat_unit')->target_id;

      $val       = $item->get('field_cbat_rental_date')->first()->getValue();
      $start_raw = trim((string) ($val['value'] ?? ''));
      $end_raw   = trim((string) ($val['end_value'] ?? ''));
      if ($start_raw === '' || $end_raw === '') {
        continue;
      }

      // Find coach_booking_events that overlap this exact slot for this unit.
      // Same overlap logic as isCoachUnitAvailable():
      //   existing.start < slot.end  AND  existing.end > slot.start
      try {
        $ids = (array) $event_storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'coach_booking_event')
          ->condition('event_bat_unit_reference', $bat_unit_id)
          ->condition('event_dates.value', $end_raw, '<')
          ->condition('event_dates.end_value', $start_raw, '>')
          ->execute();

        foreach ($event_storage->loadMultiple($ids) as $bat_event) {
          try {
            $bat_event->delete();
            $count++;
          }
          catch (\Throwable $e) {
            \Drupal::logger('court_booking')->error(
              'Failed to delete coach BAT event @eid for order @oid: @msg',
              ['@eid' => $bat_event->id(), '@oid' => $order_id, '@msg' => $e->getMessage()]
            );
          }
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('court_booking')->error(
          'Coach BAT event query failed for order @oid unit @uid: @msg',
          ['@oid' => $order_id, '@uid' => $bat_unit_id, '@msg' => $e->getMessage()]
        );
      }
    }

    return $count;
  }

  /**
   * Creates a BAT blocking event for the coach's unit for the given time range.
   *
   * @param int $bat_unit_id
   *   The coach's bat_unit entity ID.
   * @param \DateTimeImmutable $start
   *   UTC slot start.
   * @param \DateTimeImmutable $end
   *   UTC slot end.
   * @param array $context
   *   Optional: order_id, order_item_id, coach_name.
   *
   * @return bool
   *   TRUE if created successfully.
   */
  public function createCoachBlockingEvent(int $bat_unit_id, \DateTimeImmutable $start, \DateTimeImmutable $end, array $context = []): bool {
    try {
      $label = sprintf(
        'Coach Booking: %s %s → %s',
        $context['coach_name'] ?? 'Coach #' . $bat_unit_id,
        $start->format('Y-m-d H:i'),
        $end->format('H:i')
      );

      $event_storage = $this->entityTypeManager->getStorage('bat_event');
      $event = $event_storage->create([
        'type'                     => 'coach_booking_event',
        'event_bat_unit_reference' => $bat_unit_id,
        'event_dates'              => [
          'value'     => $start->format('Y-m-d\TH:i:s'),
          'end_value' => $end->format('Y-m-d\TH:i:s'),
        ],
        'title' => $label,
      ]);

      if (!empty($context['order_id']) && $event->hasField('field_order_ref')) {
        $event->set('field_order_ref', $context['order_id']);
      }
      if (!empty($context['order_item_id']) && $event->hasField('field_order_item_ref')) {
        $event->set('field_order_item_ref', $context['order_item_id']);
      }

      $event->save();
      return TRUE;
    }
    catch (\Throwable $e) {
      \Drupal::logger('court_booking')->error(
        'Failed to create coach BAT event for unit @uid: @msg',
        ['@uid' => $bat_unit_id, '@msg' => $e->getMessage()]
      );
      return FALSE;
    }
  }

  /**
   * Resolves the absolute URL for the coach photo media field, or empty string.
   */
  private function resolvePhotoUrl(object $coach): string {
    if ($coach->get('field_coach_photo')->isEmpty()) {
      return '';
    }
    $media = $coach->get('field_coach_photo')->entity;
    if (!$media) {
      return '';
    }
    $image_field = $media->hasField('field_media_image') ? 'field_media_image' : '';
    if (!$image_field || $media->get($image_field)->isEmpty()) {
      return '';
    }
    $file = $media->get($image_field)->entity;
    if (!$file) {
      return '';
    }
    try {
      return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
    }
    catch (\Throwable) {
      return '';
    }
  }

}
