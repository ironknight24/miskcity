<?php

namespace Drupal\city_map\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

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
   * @return array
   *   A render array containing theme and library attachments.
   */
  public function cityMap()
  {
    return [
      '#theme' => 'city_map',
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
   * @param int $tid
   *   The taxonomy term ID for filtering POI categories.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing count and array of POI data items.
   */
  public function getContentByTerm($tid)
  {
    $file_url_generator = \Drupal::service('file_url_generator');
    $entity_type_manager = \Drupal::entityTypeManager();

    // Load taxonomy term and extract POI image
    // field_poi_icon_image is a direct Image field (simpler)
    $poi_image_url = NULL;
    $term = $entity_type_manager->getStorage('taxonomy_term')->load($tid);
    if ($term && !$term->get('field_poi_icon_image')->isEmpty()) {
      $file_uri = $term->get('field_poi_icon_image')->entity->getFileUri();
      $poi_image_url = $file_url_generator->generateAbsoluteString($file_uri);
    }

    // Load nodes filtered by taxonomy term
    $nodes = $entity_type_manager
      ->getStorage('node')
      ->loadByProperties(['field_pois_category' => $tid]);

    $data = [];

    foreach ($nodes as $node) {
      // Get node image URL
      $image_url = NULL;
      if (!$node->get('field_image_1')->isEmpty()) {
        $file_uri = $node->get('field_image_1')->entity->getFileUri();
        $image_url = $file_url_generator->generateAbsoluteString($file_uri);
      }

      $data[] = [
        'id'             => (int) $node->id(),
        'title'          => (string) $node->label(),
        'address'        => (string) ($node->get('field_address')->value ?? ''),
        'contact_number' => (string) ($node->get('field_contact_number')->value ?? ''),
        'description'    => (string) ($node->get('field_desc')->value ?? ''),
        'latitude'       => (string) ($node->get('field_latitude')->value ?? ''),
        'longitude'      => (string) ($node->get('field_longitude')->value ?? ''),
        'price'          => (string) ($node->get('field_price')->value ?? ''),
        'timings'        => (string) ($node->get('field_timings')->value ?? ''),
        'website_url'    => (string) ($node->get('field_website_url')->value ?? ''),
        'image_url'      => $image_url,
        'created'        => (int) $node->getCreatedTime(),
        'node_url'       => (string) $node->toUrl()->toString(),
        'poi_icon'       => $poi_image_url,
      ];
    }

    return new JsonResponse([
      'count' => count($data),
      'items' => $data,
    ]);
  }
}
