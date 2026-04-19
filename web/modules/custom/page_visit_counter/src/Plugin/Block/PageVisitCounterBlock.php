<?php

namespace Drupal\page_visit_counter\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Provides a Page Visit Counter block.
 *
 * @Block(
 *   id = "page_visit_counter_block",
 *   admin_label = @Translation("Page Visit Counter"),
 * )
 */
class PageVisitCounterBlock extends BlockBase implements ContainerFactoryPluginInterface
{

    protected Connection $database;

    public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->database = $database;
    }

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('database')
        );
    }

    public function build(): array
    {
        $query = $this->database->select('visitors', 'v');
        $query->addExpression('COUNT(*)', 'total_visits');

        // Exclude error routes
        $query->condition('route', ['system.404', 'system.403', 'system.500'], 'NOT IN');

        // Exclude refreshes: current URL = referer
        $query->where('v.visitors_url != v.visitors_referer');

        $count = $query->execute()->fetchField();

        return [
            '#theme' => 'page_visit_counter_block',
            '#count' => (string) $count,
            '#cache' => ['max-age' => 0],
        ];
    }
}
