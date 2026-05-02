<?php

namespace Drupal\office_space_enquiry_api;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\UserSession;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates and serializes office_space_enquiry nodes.
 *
 * Node saves are performed under a privileged session so that API users only
 * need the 'use office space enquiry api' permission — no separate content
 * creation permission is required.
 *
 * field_email is auto-populated from the authenticated Drupal account —
 * it is never read from the request payload so it cannot be spoofed.
 */
final class OfficeSpaceEnquiryService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Creates a new office_space_enquiry node for the authenticated account.
   *
   * The node title is auto-generated as a unique hash — it does NOT need to
   * be passed in the request body.
   *
   * Request body keys (all optional):
   *   phone             (string)                     — field_phone
   *   start_date        (string YYYY-MM-DDTHH:MM:SS) — field_start_date (datetime)
   *   end_date          (string YYYY-MM-DDTHH:MM:SS) — field_end_date (datetime)
   *   notes             (string)                     — field_notes
   *   office_space_ref  (int)                        — field_office_space_ref (node ID)
   *   image             (int)                        — field_office_enquiry_image (media ID)
   *
   * field_email is always sourced from $account->getEmail(), never from $data.
   *
   * @throws \InvalidArgumentException When field values are invalid.
   */
  public function createEnquiry(AccountInterface $account, array $data): array {
    $values = [
      'type'        => 'office_space_enquiry',
      'title'       => $this->generateEnquiryTitle($account),
      'uid'         => $account->id(),
      'status'      => NodeInterface::PUBLISHED,
      // Auto-populated from the authenticated account — never from the request.
      'field_email' => $account->getEmail(),
    ];

    if (!empty($data['phone'])) {
      $values['field_phone'] = (string) $data['phone'];
    }

    if (!empty($data['start_date'])) {
      $this->assertDatetimeFormat('start_date', $data['start_date']);
      $values['field_start_date'] = $data['start_date'];
    }

    if (!empty($data['end_date'])) {
      $this->assertDatetimeFormat('end_date', $data['end_date']);
      $values['field_end_date'] = $data['end_date'];
    }

    if (!empty($data['start_date']) && !empty($data['end_date'])
        && $data['end_date'] < $data['start_date']) {
      throw new \InvalidArgumentException('end_date must be on or after start_date.');
    }

    if (!empty($data['notes'])) {
      $values['field_notes'] = (string) $data['notes'];
    }

    if (!empty($data['office_space_ref'])) {
      $refId = (int) $data['office_space_ref'];
      $refNode = $this->entityTypeManager->getStorage('node')->load($refId);
      if (!$refNode instanceof NodeInterface || $refNode->bundle() !== 'office_space') {
        throw new \InvalidArgumentException("office_space_ref $refId is not a valid Office Space node.");
      }
      $values['field_office_space_ref'] = $refId;
    }

    if (!empty($data['image'])) {
      $mediaId = (int) $data['image'];
      $media = $this->entityTypeManager->getStorage('media')->load($mediaId);
      if (!$media instanceof MediaInterface) {
        throw new \InvalidArgumentException("image $mediaId is not a valid Media entity.");
      }
      $values['field_office_enquiry_image'] = $mediaId;
    }

    $node = $this->entityTypeManager->getStorage('node')->create($values);

    // Switch to admin (uid 1) for the save so that the API user only needs
    // 'use office space enquiry api' — no content creation permission required.
    $this->accountSwitcher->switchTo(new UserSession(['uid' => 1]));
    try {
      $node->save();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $this->logger->info('Office space enquiry created: nid=@nid uid=@uid email=@email', [
      '@nid'   => $node->id(),
      '@uid'   => $account->id(),
      '@email' => $account->getEmail(),
    ]);

    return $this->serializeEnquiry($node);
  }

  /**
   * Returns all office_space_enquiry nodes owned by the account (newest first).
   *
   * @return array[]
   */
  public function loadEnquiriesForUser(AccountInterface $account): array {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'office_space_enquiry')
      ->condition('uid', $account->id())
      ->sort('created', 'DESC')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    return array_values(array_map([$this, 'serializeEnquiry'], $nodes));
  }

  /**
   * Converts a node to the API response shape.
   */
  public function serializeEnquiry(NodeInterface $node): array {
    $data = [
      'id'          => (int) $node->id(),
      'enquiry_ref' => $node->label(),
      'email'       => $node->hasField('field_email') ? $node->get('field_email')->getString() : '',
      'created'     => (int) $node->getCreatedTime(),
      'status'      => $node->isPublished() ? 'published' : 'unpublished',
    ];

    foreach ([
      'phone'    => 'field_phone',
      'notes'    => 'field_notes',
      'location' => 'field_office_enquiry_location',
    ] as $key => $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $data[$key] = $node->get($field)->getString();
      }
    }

    foreach (['start_date' => 'field_start_date', 'end_date' => 'field_end_date'] as $key => $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $data[$key] = $node->get($field)->value;
      }
    }

    $officeRef = $this->serializeOfficeSpaceRef($node);
    if ($officeRef !== NULL) {
      $data['office_space_ref'] = $officeRef;
    }

    $image = $this->serializeImage($node);
    if ($image !== NULL) {
      $data['image'] = $image;
    }

    return $data;
  }

  /**
   * Returns ['id', 'title'] for field_office_space_ref, or NULL if unset.
   */
  private function serializeOfficeSpaceRef(NodeInterface $node): ?array {
    if (!$node->hasField('field_office_space_ref') || $node->get('field_office_space_ref')->isEmpty()) {
      return NULL;
    }
    $ref = $node->get('field_office_space_ref')->entity;
    if (!$ref instanceof NodeInterface) {
      return NULL;
    }
    return ['id' => (int) $ref->id(), 'title' => $ref->label()];
  }

  /**
   * Returns image data for field_office_enquiry_image, or NULL if unset.
   */
  private function serializeImage(NodeInterface $node): ?array {
    if (!$node->hasField('field_office_enquiry_image') || $node->get('field_office_enquiry_image')->isEmpty()) {
      return NULL;
    }
    $media = $node->get('field_office_enquiry_image')->entity;
    return $media instanceof MediaInterface ? $this->resolveMediaImageUrl($media) : NULL;
  }

  /**
   * Returns media ID + absolute URL for an image media entity.
   */
  private function resolveMediaImageUrl(MediaInterface $media): array {
    $result = ['media_id' => (int) $media->id(), 'url' => NULL];

    // The source field is typically field_media_image for Image media type.
    $sourceFieldName = $media->getSource()->getConfiguration()['source_field'] ?? 'field_media_image';
    if (!$media->hasField($sourceFieldName) || $media->get($sourceFieldName)->isEmpty()) {
      return $result;
    }

    $file = $media->get($sourceFieldName)->entity;
    if ($file === NULL) {
      return $result;
    }

    $result['url'] = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
    $result['alt'] = $media->get($sourceFieldName)->alt ?? '';

    return $result;
  }

  /**
   * Generates a unique hash-based title for the enquiry node.
   *
   * Format: ENQ-{YYYYMMDD}-{8-char hash}
   * Example: ENQ-20260109-a3f7bc91
   *
   * The hash is derived from uid + email + microsecond timestamp so it is
   * unique even when the same user submits multiple enquiries rapidly.
   */
  private function generateEnquiryTitle(AccountInterface $account): string {
    $hash = substr(md5($account->id() . $account->getEmail() . microtime(TRUE)), 0, 8);
    return 'ENQ-' . date('Ymd') . '-' . strtoupper($hash);
  }

  /**
   * Throws InvalidArgumentException if the value is not a YYYY-MM-DDTHH:MM:SS datetime.
   */
  private function assertDatetimeFormat(string $field, mixed $value): void {
    if (!\DateTime::createFromFormat('Y-m-d\TH:i:s', (string) $value)) {
      throw new \InvalidArgumentException("$field must be a datetime in YYYY-MM-DDTHH:MM:SS format (e.g. 2026-01-09T15:00:00).");
    }
  }

}
