<?php

namespace Drupal\event_booking\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Booked-events and unified-bookings workflows for event booking APIs.
 */
class EventBookingBookingsService extends EventBookingBaseService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventBookingEventNodeResolver $eventNodeResolver,
    protected EventBookingOrderMapBuilder $orderMapBuilder,
    protected EventBookingRowsBuilder $rowsBuilder,
    protected EventBookingPager $pager,
    protected EventBookingUnifiedBookingsService $unifiedBookings,
  ) {}

  public function getMyBookedEvents(AccountInterface $account, string $bucket, array $params): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    if (!in_array($bucket, ['upcoming', 'completed'], TRUE)) {
      return ['status' => 400, 'data' => ['message' => (string) $this->t('Invalid events bucket.')]];
    }
    return ['status' => 200, 'data' => $this->buildBookedEventsPayload($account, $bucket, $params)];
  }

  public function getUnifiedBookings(AccountInterface $account, array $params): array {
    return $this->unifiedBookings->getUnifiedBookings($account, $params, [$this, 'getMyBookedEvents']);
  }

  private function buildBookedEventsPayload(AccountInterface $account, string $bucket, array $params): array {
    $page = max(0, (int) ($params['page'] ?? 0));
    $limit = max(1, min(50, (int) ($params['limit'] ?? 10) ?: 10));
    $settings = $this->configFactory->get('event_booking.settings');
    $bundle = trim((string) ($settings->get('event_node_bundle') ?: 'events'));
    $field = trim((string) ($settings->get('event_ticket_variation_field') ?: 'field_prod_event_variation'));
    if ($bundle === '' || $field === '') {
      return $this->pager->build([], $page, $limit);
    }
    [$bundle, $has_field] = $this->eventNodeResolver->resolveBundleForEventVariationField($bundle, $field);
    if (!$has_field || $bundle === '') {
      return $this->pager->build([], $page, $limit);
    }
    return $this->buildRowsForResolvedBundle($account, $bucket, $params, $settings, $bundle, $field, $page, $limit);
  }

  private function buildRowsForResolvedBundle(AccountInterface $account, string $bucket, array $params, $settings, string $bundle, string $field, int $page, int $limit): array {
    $booking_map = $this->orderMapBuilder->loadCompletedBookedVariationOrderMap((int) $account->id());
    if ($booking_map['variation_ids'] === []) {
      return $this->pager->build([], $page, $limit);
    }
    $nodes = $this->loadEventNodes($bundle, $field, $booking_map['variation_ids']);
    $field_map = $this->fieldMap($settings);
    $rows = $this->rowsBuilder->build($nodes, $account, $bucket, $field_map, $field, $booking_map['variation_orders'], mb_strtolower(trim((string) ($params['q'] ?? ''))), \Drupal::time()->getCurrentTime());
    return $this->pager->build($this->rowsBuilder->sortAndStrip($rows, $bucket), $page, $limit);
  }

  /**
   * @param int[] $variation_ids
   * @return \Drupal\node\NodeInterface[]
   */
  private function loadEventNodes(string $bundle, string $field, array $variation_ids): array {
    $node_ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition($field . '.target_id', $variation_ids, 'IN')
      ->execute();
    return $node_ids ? $this->entityTypeManager->getStorage('node')->loadMultiple(array_values($node_ids)) : [];
  }

  /**
   * @return array<string, string>
   */
  private function fieldMap($settings): array {
    return [
      'date' => (string) ($settings->get('event_date_range_field') ?: 'field_event_date_time'),
      'image' => (string) ($settings->get('event_image_field') ?: 'field_event_image'),
      'location' => (string) ($settings->get('event_location_field') ?: 'field_event_location'),
    ];
  }

}
