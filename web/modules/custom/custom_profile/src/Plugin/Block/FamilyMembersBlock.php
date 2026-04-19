<?php
namespace Drupal\custom_profile\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\custom_profile\Service\ProfileService;

/**
 * Provides a Family Members block.
 *
 * @Block(
 *   id = "custom_profile_family_members_block",
 *   admin_label = @Translation("Family Members Block"),
 *   category = @Translation("Profile")
 * )
 */
class FamilyMembersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $profileService;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ProfileService $profile_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->profileService = $profile_service;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('custom_profile.profile_service')
    );
  }

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
      '#cache' => ['max-age' => 0], // optional, prevents caching if session-dependent
    ];
  }
}
