<?php

namespace Drupal\ideas\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\node\Entity\Node;

/**
 * Processes items in the Ideas Create queue.
 *
 * @QueueWorker(
 *   id = "ideas_create_queue",
 *   title = @Translation("Ideas Create Queue"),
 *   cron = {"time" = 1800}
 * )
 */
class IdeasCreateWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    try {
      // Use the stored UID; default to 1 (admin) if missing.
      $uid = !empty($data['uid']) ? $data['uid'] : 1;

      // Create unpublished idea node with correct author.
      $node = Node::create([
        'type' => 'ideas',
        'title' => $data['title'],
        'field_idea_author' => $data['author'],
        'field_idea_content' => [
          'value' => $data['body'],
          'format' => 'basic_html',
        ],
        'field_ideas_categories' => [
          'target_id' => $data['category_id'],
        ],
        'field_idea_image' => $data['image_url'],
        'status' => 0,
        'uid' => $uid,
      ]);
      $node->save();

      \Drupal::logger('ideas_queue')->info('Processed idea: @title by user @uid', [
        '@title' => $data['title'],
        '@uid' => $uid,
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('ideas_queue')->error('Queue processing failed: @msg', ['@msg' => $e->getMessage()]);
    }
  }

}
