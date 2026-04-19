<?php

namespace Drupal\city_map\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * CityMapController class.
 *
 * Handles rendering of the city map page and provides API endpoints
 * for retrieving POI (Point of Interest) data filtered by category.
 * Manages content delivery for map markers and associated node data.
 */
class CityMapController extends ControllerBase
{

  /**
   * Renders the city map page.
   *
   * Returns a render array with the city map theme template and attaches
   * the required JavaScript/CSS library for map functionality.
   *
   * @return array
   *   A render array containing theme and library attachments.
   */
  public function cityMap()
  {
    return [
      // Specify the theme template for rendering the city map
      '#theme' => 'city_map',
      // Attach the city map library containing JS and CSS assets
      '#attached' => [
        'library' => [
          'city_map/city-map-library',
        ],
      ]
    ];
  }

  /**
   * Retrieves POI content filtered by taxonomy term ID.
   *
   * Loads nodes that have the specified POI category term, extracts
   * relevant field data (address, coordinates, images, etc.), and returns
   * as JSON response for map marker display.
   *
   * @param int $tid
   *   The taxonomy term ID for filtering POI categories.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing count and array of POI data items.
   */
  public function getContentByTerm($tid)
  {
    // Get file URL generator service for generating absolute image URLs
    $file_url_generator = \Drupal::service('file_url_generator');

    // Load nodes that have the specified POI category term
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['field_pois_category' => $tid]);

    // Initialize empty array for POI data
    $data = [];

    // Process each node to extract POI information
    foreach ($nodes as $node) {
      // Get Image URL with safe check for empty field
      $image_url = NULL;
      if (!$node->get('field_image_1')->isEmpty()) {
        // Get file URI and generate absolute URL
        $file_uri = $node->get('field_image_1')->entity->getFileUri();
        $image_url = $file_url_generator->generateAbsoluteString($file_uri);
      }

      // Build POI data array with all relevant fields
      $data[] = [
        'id' => $node->id(),
        'title' => $node->label(),
        'address' => $node->get('field_address')->value ?? '',
        'contact_number' => $node->get('field_contact_number')->value ?? '',
        'description' => $node->get('field_desc')->value ?? '',
        'latitude' => $node->get('field_latitude')->value ?? '',
        'longitude' => $node->get('field_longitude')->value ?? '',
        'price' => $node->get('field_price')->value ?? '',
        'timings' => $node->get('field_timings')->value ?? '',
        'website_url' => $node->get('field_website_url')->value ?? '',
        'image_url' => $image_url,
        'created' => $node->getCreatedTime(),
        'node_url' => $node->toUrl()->toString(),
      ];
    }

    // Return JSON response with count and POI data array
    return new JsonResponse([
      'count' => count($data),
      'items' => $data,
    ]);
  }
}
