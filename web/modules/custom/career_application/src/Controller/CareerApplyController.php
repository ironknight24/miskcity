<?php

namespace Drupal\career_application\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\node\Entity\Node;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Controller for career application pages.
 *
 * Handles displaying user job applications, success confirmations,
 * and detailed application information.
 */
class CareerApplyController extends ControllerBase
{
    // Constant for Drupal render array theme key
    private const THEME_KEY = '#theme';

    /**
     * Display list of jobs the user has applied for.
     *
     * Retrieves all career applications for the currently logged-in user
     * from the career_applications table and loads associated job node data.
     * Results are sorted by most recent application date.
     *
     * @return array
     *   Render array with theme 'career_applications_list' and applications data
     */
    public function userApplications()
    {
        // Get the current user's ID
        $uid = $this->currentUser()->id();
        $applications = [];

        // Query the career_applications table for user's applications
        $result = \Drupal::database()->select('career_applications', 'ca')
            ->fields('ca', ['nid', 'applied', 'first_name', 'last_name'])
            ->condition('uid', $uid)
            ->orderBy('applied', 'DESC')
            ->execute();

        // Iterate through results and load associated career node data
        foreach ($result as $record) {
            // Load the career job posting node
            $node = \Drupal\node\Entity\Node::load($record->nid);
            
            // Only process valid career nodes
            if ($node && $node->bundle() === 'careers') {
                $applications[] = [
                    'title' => $node->label(),
                    'experience' => $node->get('field_experience')->value ?? '',
                    'location' => $node->get('field_job_location')->value ?? '',
                    'nid' => $node->id(),
                    'applied' => $record->applied,
                ];
            }
        }

        // Return render array with applications list
        return [
            self::THEME_KEY => 'career_applications_list',
            '#applications' => $applications,
            // '#attached' => ['library' => ['career_application/your-custom-styles']],
        ];
    }

    /**
     * Display success page after application submission.
     *
     * Shows a confirmation message to the user indicating their
     * job application has been successfully submitted.
     *
     * @return array
     *   Render array with theme 'career_application_success'
     */
    public function success()
    {
        return [
            self::THEME_KEY => 'career_application_success',
            '#title' => $this->t('Application Submitted'),
        ];
    }

    /**
     * Display detailed information about a specific application.
     *
     * Retrieves a user's application details for a specific job posting,
     * including the job node data and uploaded resume file URL.
     * Throws 404 error if application or job not found.
     *
     * @param int $nid
     *   The node ID of the career job posting
     *
     * @return array
     *   Render array with theme 'career_application_detail' containing
     *   job node, application details, and resume URL
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     *   If the application record or job node does not exist
     */
    public function applicationDetails($nid)
    {
        // Get the current user's ID for security/permission checking
        $uid = $this->currentUser()->id();

        // Retrieve the application record from career_applications table
        $record = \Drupal::database()->select('career_applications', 'ca')
            ->fields('ca')
            ->condition('uid', $uid)
            ->condition('nid', $nid)
            ->execute()
            ->fetchObject();

        // Load the career job posting node
        $node = \Drupal\node\Entity\Node::load($nid);

        // Return 404 if application or job node not found
        if (!$record || !$node) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        // Load resume file URL if one was uploaded
        $resume_url = NULL;
        if (!empty($record->resume_fid)) {
            // Load the file entity by its ID
            $file = \Drupal\file\Entity\File::load($record->resume_fid);
            if ($file) {
                // Generate absolute URL to the resume file
                $resume_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
            }
        }

        // Return render array with application details
        return [
            self::THEME_KEY => 'career_application_detail',
            '#node' => $node,
            '#application' => $record,
            '#resume_url' => $resume_url,
        ];
    }
}
