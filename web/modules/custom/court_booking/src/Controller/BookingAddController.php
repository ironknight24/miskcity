<?php

namespace Drupal\court_booking\Controller;

use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\court_booking\CourtBookingApiService;
use Drupal\court_booking\CourtBookingVariationThumbnail;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST handler: validate slot with AvailabilityManager and add order item.
 */
class BookingAddController extends ControllerBase {

  public function __construct(
    protected CourtBookingApiService $courtBookingApi,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('court_booking.api'),
    );
  }

  /**
   * Adds a lesson slot to the cart as JSON API for fetch().
   */
  public function add(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    // Legacy/stale clients may POST add from amenities; redirect to court node instead of cart.
    $ref = $request->headers->get('Referer') ?? '';
    $referer_path = (string) (parse_url($ref, PHP_URL_PATH) ?: '');
    $from_amenities = str_starts_with($referer_path, '/amenities/');
    $redirect_to = isset($data['redirect_to']) ? (string) $data['redirect_to'] : '';
    if ($from_amenities && $redirect_to === '') {
      $variation_id = (int) ($data['variation_id'] ?? 0);
      $start = isset($data['start']) ? (string) $data['start'] : '';
      $end = isset($data['end']) ? (string) $data['end'] : '';
      $variation = $variation_id > 0 ? ProductVariation::load($variation_id) : NULL;
      if ($variation) {
        $court_node = CourtBookingVariationThumbnail::courtNode($variation);
        if ($court_node && $court_node->access('view')) {
          $query = [
            'variation' => $variation_id,
            'start' => $start,
            'end' => $end,
          ];
          $redirect = $court_node->toUrl('canonical', ['absolute' => TRUE, 'query' => $query])->toString();
          return new JsonResponse([
            'status' => 'ok',
            'redirect' => $redirect,
          ], 200);
        }
      }
    }

    $result = $this->courtBookingApi->addBookingLineItem($this->currentUser(), $data, [
      'include_legacy_redirect' => TRUE,
      'include_rest_fields' => FALSE,
    ]);
    return new JsonResponse($result['data'], $result['status']);
  }

}
