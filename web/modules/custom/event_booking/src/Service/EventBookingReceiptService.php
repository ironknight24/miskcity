<?php

namespace Drupal\event_booking\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\node\NodeInterface;

/**
 * Receipt workflows for event booking APIs.
 */
class EventBookingReceiptService extends EventBookingBaseService
{

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected OrderRefreshInterface $orderRefresh,
    protected ConfigFactoryInterface $configFactory,
    protected EventBookingEventNodeResolver $eventNodeResolver,
    protected EventBookingNodeSerializer $nodeSerializer,
  ) {}

  public function buildReceipt(OrderInterface $order, AccountInterface $account): array
  {
    $response = NULL;

    if ((int) $order->getCustomerId() !== (int) $account->id()) {
      $response = [
        'status' => 403,
        'data' => [
          'message' => (string) $this->t('Access denied.')
        ]
      ];
    } else {
      $fresh = $this->entityTypeManager
        ->getStorage('commerce_order')
        ->load($order->id());

      if (!$fresh instanceof OrderInterface) {
        $response = [
          'status' => 404,
          'data' => [
            'message' => (string) $this->t('Order not found.')
          ]
        ];
      } else {
        $this->orderRefresh->refresh($fresh);

        if ($fresh->getState()->getId() !== 'completed') {
          $response = [
            'status' => 409,
            'data' => [
              'message' => (string) $this->t('Receipt is only available for completed orders.'),
              'state' => $fresh->getState()->getId(),
            ]
          ];
        } else {
          $response = [
            'status' => 200,
            'data' => $this->buildReceiptPayload($fresh, $account),
          ];
        }
      }
    }

    return $response;
  }

  /**
   * @return array<string, mixed>
   */
  private function buildReceiptPayload(OrderInterface $order, AccountInterface $account): array
  {
    $settings = $this->configFactory->get('event_booking.settings');
    [$lines, $events] = $this->buildReceiptLines($order, $account, $settings);
    return [
      'order_id' => (int) $order->id(),
      'state' => $order->getState()->getId(),
      'total' => $order->getTotalPrice() ? $order->getTotalPrice()->__toString() : NULL,
      'line_items' => $lines,
      'events' => array_values($events),
    ];
  }

  /**
   * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
   */
  private function buildReceiptLines(OrderInterface $order, AccountInterface $account, $settings): array
  {
    $lines = [];
    $events = [];
    $cache = [];
    foreach ($order->getItems() as $item) {
      [$line, $event_id, $event] = $this->buildReceiptLine($item, $account, $settings, $cache);
      $lines[] = $line;
      if ($event_id !== NULL && $event !== NULL) {
        $events[$event_id] = $event;
      }
    }
    return [$lines, $events];
  }

  private function buildReceiptLine(object $item, AccountInterface $account, $settings, array &$cache): array
  {
    $purchased = $item->getPurchasedEntity();
    $line = [
      'order_item_id' => (int) $item->id(),
      'title' => $item->getTitle(),
      'quantity' => (string) $item->getQuantity(),
      'variation_id' => $purchased instanceof ProductVariationInterface ? (int) $purchased->id() : NULL,
    ];
    if (!$purchased instanceof ProductVariationInterface) {
      return [$line, NULL, NULL];
    }
    $node = $this->resolveEventNode($purchased, $account, $settings, $cache);
    return $node instanceof NodeInterface
      ? $this->withEventData($line, $node, $settings)
      : [$line, NULL, NULL];
  }

  private function resolveEventNode(ProductVariationInterface $variation, AccountInterface $account, $settings, array &$cache): ?NodeInterface
  {
    $vid = (string) $variation->id();
    if (array_key_exists($vid, $cache)) {
      return $cache[$vid];
    }
    $node = $this->eventNodeResolver->resolve($variation, $settings);
    $cache[$vid] = $node instanceof NodeInterface && $node->access('view', $account) ? $node : NULL;
    return $cache[$vid];
  }

  private function withEventData(array $line, NodeInterface $node, $settings): array
  {
    $serialized = $this->nodeSerializer->serializeEventNode(
      $node,
      (string) ($settings->get('event_date_range_field') ?: 'field_event_date_time'),
      (string) ($settings->get('event_image_field') ?: 'field_event_image'),
      (string) ($settings->get('event_location_field') ?: 'field_event_location'),
    );
    $event_id = (int) $node->id();
    $line['event_nid'] = $event_id;
    $line['event'] = $serialized;
    return [$line, $event_id, $serialized];
  }
}
