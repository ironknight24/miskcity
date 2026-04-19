<?php

namespace Drupal\login_logout\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'Logged in details' block.
 *
 * @Block(
 *   id = "logged_in_details",
 *   admin_label = @Translation("Logged in details")
 * )
 */
class LoggedInDetailsBlock extends BlockBase implements ContainerFactoryPluginInterface
{
    private const NULL_STRING = 'null';

    /**
     * Current user service.
     *
     * @var \Drupal\Core\Session\AccountProxyInterface
     */
    protected $currentUser;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('current_user')
        );
    }

    /**
     * Constructs a new LoggedInDetailsBlock instance.
     */
    public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountProxyInterface $current_user)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->currentUser = $current_user;
    }

    public function getCacheMaxAge()
    {
        return 0;
    }


    /**
     * {@inheritdoc}
     */
    public function build()
    {
        $session = \Drupal::request()->getSession();
        $user_data = $session->get('api_redirect_result') ?? [];
        $account = $user_data;

        $profilePic = $user_data['profilePic'] ?? NULL;

        $avatar_url = ($profilePic !== NULL && $profilePic !== self::NULL_STRING && $profilePic !== '')
            ? htmlspecialchars($profilePic, ENT_QUOTES, 'UTF-8')
            : '/themes/custom/engage_theme/images/Profile/profile_pic.png';

        if (!$account) {
            return [];
        }

        return [
            '#theme' => 'logged_in_details_block',
            '#display_name' => $account['firstName'] . ' ' . $account['lastName'],
            '#email' => $account['emailId'] ?? '',
            '#avatar_url' => $avatar_url,
            '#attached' => [
                'library' => [
                    'login_logout/logged_in_details',
                ],
            ],
        ];
    }
}
