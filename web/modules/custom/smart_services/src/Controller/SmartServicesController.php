<?php

namespace Drupal\smart_services\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SmartServicesController extends ControllerBase
{

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a SmartServicesController object.
   */
  public function __construct(RendererInterface $renderer)
  {
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self
  {
    return new static(
      $container->get('renderer')
    );
  }

  /**
   * Renders the main Smart Services landing page.
   */
  public function landing()
  {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree('smart_services', 0, 1, TRUE);

    $terms_with_children = array_filter($terms, function ($term) {
      $children = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadChildren($term->id());
      return !empty($children);
    });

    return [
      '#theme' => 'smart_services_list',
      '#terms' => $terms_with_children,
      '#parent_term' => NULL,
      '#siblings' => NULL,
      '#current_term' => NULL,
      '#attached' => [
        'library' => [
          'core/drupal.ajax',
          'smart_services/smart_services_ajax',
        ],
      ],
      '#prefix' => '<div id="smart-services-wrapper">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * Renders the child taxonomy terms of a given term via AJAX or full page.
   */
  public function termView($tid, Request $request)
  {
    $term = Term::load($tid);
    if (!$term || $term->bundle() !== 'smart_services') {
      throw new NotFoundHttpException();
    }

    $children = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadChildren($tid);

    $parents = $term->get('parent')->getValue();
    $parent_tid = !empty($parents) ? $parents[0]['target_id'] : 0;

    $siblings = [];
    if ($parent_tid !== 0) {
      $siblings = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadTree('smart_services', $parent_tid, 1, TRUE);
    }

    $parent_term = $parent_tid ? Term::load($parent_tid) : NULL;

    $render_array = [
      '#theme' => 'smart_services_list',
      '#terms' => $children,
      '#parent_term' => $parent_term,
      '#siblings' => $siblings,
      '#current_term' => $term,
      '#attached' => [
        'library' => [
          'core/drupal.ajax',
          'smart_services/smart_services_ajax',
        ],
      ],
      '#prefix' => '<div id="smart-services-wrapper">',
      '#suffix' => '</div>',
    ];

    if (!$request->isXmlHttpRequest()) {
      return $render_array;
    }

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#smart-services-wrapper', $this->renderer->renderRoot($render_array)));
    return $response;
  }

  /**
   * Title callback for dynamic page titles.
   */
  public function getTermTitle($tid)
  {
    $term = Term::load($tid);
    return $term ? $term->label() : $this->t('Smart Services');
  }
}
