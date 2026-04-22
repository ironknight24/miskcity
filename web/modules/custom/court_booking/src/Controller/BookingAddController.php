<?php

namespace Drupal\court_booking\Controller;

use Drupal\court_booking\CourtBookingApiService;
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

    $result = $this->courtBookingApi->addBookingLineItem($this->currentUser(), $data, [
      'include_legacy_redirect' => TRUE,
      'include_rest_fields' => FALSE,
    ]);
    return new JsonResponse($result['data'], $result['status']);
  }

}
