<?php

namespace Drupal\reportgrievance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class GrievanceSuccessController extends ControllerBase {

    protected $secret = 'my_secret_key';

    /**
     * Display grievance success page.
     */
    public function content($token) {
        if (!$token) {
            throw new AccessDeniedHttpException('Invalid request.');
        }

        // Retrieve grievance_id from key-value storage
        $grievance_id = \Drupal::keyValue('reportgrievance.token_map')->get($token);
        if (!$grievance_id) {
            throw new AccessDeniedHttpException('Invalid token.');
        }

        // Optional: Delete token after first use if you want one-time access
        // \Drupal::keyValue('reportgrievance.token_map')->delete($token);

        return [
            '#theme' => 'grievance_success',
            '#response_data' => [
                'grievance_id' => $grievance_id,
            ],
        ];
    }
}
