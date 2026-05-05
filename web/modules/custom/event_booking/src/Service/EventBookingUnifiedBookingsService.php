<?php

namespace Drupal\event_booking\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\court_booking\CourtBookingApiService;

/**
 * Builds unified court/event booking payloads.
 */
class EventBookingUnifiedBookingsService extends EventBookingBaseService {

  public function __construct(
    protected CourtBookingApiService $courtBookingApi,
    protected EventBookingPager $pager,
  ) {}

  public function getUnifiedBookings(AccountInterface $account, array $params, callable $event_loader): array {
    if (!$account->isAuthenticated()) {
      return ['status' => 401, 'data' => ['message' => (string) $this->t('Authentication required.')]];
    }
    $context = $this->normalizeUnifiedParams($params);
    $segments = $this->emptySegments($context);
    $error = $this->appendRequestedSegments($account, $context, $segments, $event_loader);
    return $error ?? ['status' => 200, 'data' => [
      'bucket' => $context['bucket'],
      'filters' => ['q' => $context['q'], 'sport_tid' => $context['sport_tid'], 'kind' => $context['kind']],
      'segments' => $segments,
    ]];
  }

  /**
   * @return array<string, mixed>
   */
  private function normalizeUnifiedParams(array $params): array {
    $court_limit = max(1, min(50, (int) ($params['court_limit'] ?? 10)));
    $event_limit = max(1, min(50, (int) ($params['event_limit'] ?? 10)));
    $bucket_raw = mb_strtolower(trim((string) ($params['bucket'] ?? '')));
    $kind_raw = mb_strtolower(trim((string) ($params['kind'] ?? '')));
    return [
      'bucket' => in_array($bucket_raw, ['upcoming', 'past'], TRUE) ? $bucket_raw : 'upcoming',
      'kind' => in_array($kind_raw, ['all', 'court', 'event'], TRUE) ? $kind_raw : 'all',
      'q' => trim((string) ($params['q'] ?? '')),
      'sport_tid' => max(0, (int) ($params['sport_tid'] ?? 0)),
      'court_page' => max(0, (int) ($params['court_page'] ?? 0)),
      'court_limit' => $court_limit,
      'event_page' => max(0, (int) ($params['event_page'] ?? 0)),
      'event_limit' => $event_limit,
    ];
  }

  private function emptySegments(array $context): array {
    return [
      'court' => ['rows' => [], 'pager' => $this->pager->build([], 0, $context['court_limit'])['pager']],
      'event' => ['rows' => [], 'pager' => $this->pager->build([], 0, $context['event_limit'])['pager']],
    ];
  }

  private function appendRequestedSegments(AccountInterface $account, array $context, array &$segments, callable $event_loader): ?array {
    if ($context['kind'] === 'all' || $context['kind'] === 'court') {
      $error = $this->appendCourtSegment($account, $context, $segments);
      if ($error !== NULL) {
        return $error;
      }
    }
    return ($context['kind'] === 'all' || $context['kind'] === 'event')
      ? $this->appendEventSegment($account, $context, $segments, $event_loader)
      : NULL;
  }

  private function appendCourtSegment(AccountInterface $account, array $context, array &$segments): ?array {
    $result = $this->courtBookingApi->buildMyBookingsResponse($account, $context['bucket'], [
      'page' => $context['court_page'],
      'limit' => $context['court_limit'],
      'q' => $context['q'],
      'sport_tid' => $context['sport_tid'],
    ]);
    if ($result['status'] !== 200) {
      return $result;
    }
    $segments['court'] = ['rows' => $this->kindRows((array) ($result['data']['rows'] ?? []), 'court'), 'pager' => (array) ($result['data']['pager'] ?? $segments['court']['pager'])];
    return NULL;
  }

  private function appendEventSegment(AccountInterface $account, array $context, array &$segments, callable $event_loader): ?array {
    $event_bucket = $context['bucket'] === 'past' ? 'completed' : 'upcoming';
    $result = $event_loader($account, $event_bucket, ['page' => $context['event_page'], 'limit' => $context['event_limit'], 'q' => $context['q']]);
    if ($result['status'] !== 200) {
      return $result;
    }
    $segments['event'] = ['rows' => $this->kindRows((array) ($result['data']['rows'] ?? []), 'event'), 'pager' => (array) ($result['data']['pager'] ?? $segments['event']['pager'])];
    return NULL;
  }

  private function kindRows(array $rows, string $kind): array {
    $typed = [];
    foreach ($rows as $row) {
      if (is_array($row)) {
        $typed[] = ['kind' => $kind] + $row;
      }
    }
    return $typed;
  }

}
