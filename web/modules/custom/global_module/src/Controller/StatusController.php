<?php

namespace Drupal\global_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * StatusController class.
 *
 * Handles rendering of status pages with dynamic content based on query parameters.
 * Displays status information, messages, and form data passed through the request.
 */
class StatusController extends ControllerBase {

  /**
   * Renders the status page with query parameter information.
   *
   * Retrieves status, message, and form data from the request query string and
   * passes them to the Drupal theme system for rendering. Disables caching to
   * ensure fresh data is always displayed.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request containing query parameters.
   *
   * @return array
   *   A render array that will be processed by Drupal's theme system.
   *   Contains status code, message text, form data, and cache settings.
   */
  public function statusPage(Request $request) {
    // Extract the status code from query parameters, default to 0 if not provided
    $status = $request->query->get('status', 0);
    
    // Extract the message text from query parameters, default to generic message if not provided
    $message = $request->query->get('message', 'No message provided.');
    
    // Extract form data from query parameters, default to 'unknown' if not provided
    $formData = $request->query->get('formData', 'unknown');

    // Return a render array with theme template and variables
    return [
      // Specify the theme template to use for rendering
      '#theme' => 'status',
      
      // Pass status code to the theme template
      '#status' => $status,
      
      // Pass message text to the theme template
      '#message' => $message,
      
      // Pass form data to the theme template
      '#form_data' => $formData,
      
      // Cache configuration - disable caching (max-age 0) to ensure fresh content
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }
}
