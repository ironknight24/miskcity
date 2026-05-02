<?php

namespace Drupal\office_space_enquiry_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\office_space_enquiry_api\OfficeSpaceEnquiryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST controller for office space enquiry endpoints.
 */
final class OfficeSpaceEnquiryRestController extends ControllerBase {

  public function __construct(
    private readonly OfficeSpaceEnquiryService $enquiryService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('office_space_enquiry_api.service'),
    );
  }

  /**
   * POST /rest/v1/office-space-enquiry
   *
   * Creates a new office_space_enquiry node. The submitter's email is derived
   * entirely from the authenticated account — it must not be passed in the body.
   *
   * Request body (JSON):
   * {
   *   "name":             "John Doe",          // required — stored as node title
   *   "phone":            "+966 50 000 0000",  // optional
   *   "company":          "ACME Corp",         // optional
   *   "space_type":       "private_office",    // optional
   *   "message":          "Looking for ...",   // optional
   *   "preferred_date":   "2026-06-01",        // optional (YYYY-MM-DD)
   *   "number_of_seats":  4                    // optional (integer)
   * }
   *
   * Response 201: serialized enquiry object (see OfficeSpaceEnquiryService::serializeEnquiry).
   */
  public function submit(Request $request): JsonResponse {
    $body = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($body)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
    }

    try {
      $result = $this->enquiryService->createEnquiry($this->currentUser(), $body);
      return new JsonResponse($result, Response::HTTP_CREATED);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
    catch (\Exception $e) {
      return new JsonResponse(
        ['error' => 'Submission failed. Please try again later.'],
        Response::HTTP_INTERNAL_SERVER_ERROR,
      );
    }
  }

  /**
   * GET /rest/v1/office-space-enquiry/my-enquiries
   *
   * Returns all enquiries belonging to the authenticated user.
   */
  public function myEnquiries(): JsonResponse {
    $enquiries = $this->enquiryService->loadEnquiriesForUser($this->currentUser());
    return new JsonResponse($enquiries);
  }

  /**
   * GET /rest/v1/office-space-enquiry/{node}
   *
   * Returns a single enquiry. Access layer (OfficeSpaceOAuthAccess::ownEnquiry)
   * already verified the node belongs to the current user.
   */
  public function getEnquiry(NodeInterface $node): JsonResponse {
    return new JsonResponse($this->enquiryService->serializeEnquiry($node));
  }

}
