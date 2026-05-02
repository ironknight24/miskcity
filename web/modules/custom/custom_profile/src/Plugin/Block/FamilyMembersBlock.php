<?php

namespace Drupal\custom_profile\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\custom_profile\Service\ProfileService;

/**
 * Provides a block displaying the current user's family members.
 *
 * Reads the citizen platform user ID from the session (populated during the
 * post-login redirect flow) and delegates retrieval to ProfileService, which
 * fetches the list from the external citizen-app API and masks PII fields
 * before returning. The block is never cached because its content is
 * session-specific.
 *
 * @Block(
 *   id = "custom_profile_family_members_block",
 *   admin_label = @Translation("Family Members Block"),
 *   category = @Translation("Profile")
 * )
 */
class FamilyMembersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The profile service used to fetch family members from the external API.
   *
   * @var \Drupal\custom_profile\Service\ProfileService
   */
  protected $profileService;

  /**
   * Constructs a FamilyMembersBlock.
   *
   * @param array $configuration
   *   Plugin configuration array.
   * @param string $plugin_id
   *   The plugin ID for this block.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\custom_profile\Service\ProfileService $profile_service
   *   The profile service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ProfileService $profile_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->profileService = $profile_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('custom_profile.profile_service')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Reads the user ID from the session and fetches the family-member list via
   * ProfileService. When no user ID is present (e.g. anonymous context), an
   * empty members array is passed to the template. Cache max-age is set to
   * zero because the data is tied to the current session, not a cacheable
   * entity.
   */
  public function build() {
    $session = \Drupal::request()->getSession();
    $user_data = $session->get('api_redirect_result') ?? [];
    $user_id = $user_data['userId'] ?? NULL;

    $members = [];

    if ($user_id) {
      $members = $this->profileService->fetchFamilyMembers($user_id);
    }

    return [
      '#theme' => 'family_members_block',
      '#members' => $members,
      // Prevent caching because content depends on the current session.
      '#cache' => ['max-age' => 0],
    ];
  }

}
