<?php

namespace Drupal\court_booking\Controller;

use Drupal\court_booking\CourtBookingApiService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * JSON: validated slot price preview (Commerce-aligned, no cart writes).
 */
final class PricePreviewController extends ControllerBase {

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
   * POST JSON body: variation_id, start, end, quantity (optional).
   */
  public function preview(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }
    $result = $this->courtBookingApi->buildPricePreviewResponse($data, $this->currentUser());

    return new JsonResponse($result['data'], $result['status']);
  }

}
