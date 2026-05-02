<?php

namespace Drupal\dual_language_switch\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Single link toggling between default and configured secondary language.
 *
 * If the interface is in a third (or more) enabled language, the block offers
 * a link to the site default language so visitors can return to the primary UI.
 */
#[Block(
  id: 'dual_language_switcher',
  admin_label: new TranslatableMarkup('Dual language switcher'),
  category: new TranslatableMarkup('Multilingual'),
)]
final class DualLanguageSwitcherBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigurableLanguageManagerInterface $languageManager,
    protected PathMatcherInterface $pathMatcher,
    protected ConfigFactoryInterface $configFactory,
    protected RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('language_manager'),
      $container->get('path.matcher'),
      $container->get('config.factory'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $access = $this->languageManager->isMultilingual()
      ? AccessResult::allowed()
      : AccessResult::forbidden();
    return $access->addCacheTags(['config:configurable_language_list']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $secondary = trim((string) $this->configFactory->get('dual_language_switch.settings')->get('secondary_langcode'));
    if ($secondary === '') {
      return $this->buildEmpty();
    }

    $default_id = $this->languageManager->getDefaultLanguage()->getId();
    $languages = $this->languageManager->getLanguages();
    if (!isset($languages[$secondary]) || $secondary === $default_id) {
      return $this->buildEmpty();
    }

    $current_id = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    $target_id = $this->resolveTargetLanguage($current_id, $default_id, $secondary);

    $url = $this->resolveUrl();
    $switch = $this->languageManager->getLanguageSwitchLinks(LanguageInterface::TYPE_INTERFACE, $url);
    if (!$switch || empty($switch->links) || !isset($switch->links[$target_id])) {
      return $this->buildEmpty();
    }

    $link = $switch->links[$target_id];
    $link['title'] = $this->nativeLanguageDisplayName(
      $target_id,
      $this->normalizeLinkTitle($link['title'] ?? '', $target_id, $languages)
    );

    $one = [$target_id => $link];
    $build = [
      '#theme' => 'links__language_block',
      '#links' => $one,
      '#attributes' => [
        'class' => [
          'language-switcher-dual',
          'language-switcher-' . $switch->method_id,
        ],
      ],
      '#set_active_class' => TRUE,
    ];

    $cache = BubbleableMetadata::createFromRenderArray($build)
      ->addCacheContexts(['languages:language_interface', 'url.path', 'url.query_args', 'url.site'])
      ->addCacheTags(['config:configurable_language_list', 'config:dual_language_switch.settings']);

    foreach ($one as $link) {
      if (isset($link['url']) && $link['url'] instanceof Url) {
        $cache->addCacheableDependency($link['url']->access(NULL, TRUE));
      }
    }
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Determines the target language for the switch link.
   *
   * When the current language is the site default, target the secondary.
   * In all other cases (current is secondary, or a third language), target
   * the default so visitors can always return to the primary UI.
   *
   * @param string $current_id
   *   The active interface language code.
   * @param string $default_id
   *   The site default language code.
   * @param string $secondary
   *   The configured secondary language code.
   *
   * @return string
   *   The language code to link to.
   */
  private function resolveTargetLanguage(string $current_id, string $default_id, string $secondary): string {
    if ($current_id === $default_id) {
      return $secondary;
    }
    return $default_id;
  }

  /**
   * Resolves the URL to pass to getLanguageSwitchLinks().
   *
   * Uses the front-page route when on the front page or when no route object
   * is available (e.g. 404 pages); otherwise uses the current route match.
   *
   * @return \Drupal\Core\Url
   *   The URL to generate switch links for.
   */
  private function resolveUrl(): Url {
    if ($this->pathMatcher->isFrontPage() || !$this->routeMatch->getRouteObject()) {
      return Url::fromRoute('<front>');
    }
    return Url::fromRouteMatch($this->routeMatch);
  }

  /**
   * Returns a plain string title for a language switch link.
   *
   * Core's language negotiation may return the title as a string, a stringable
   * object (e.g. TranslatableMarkup), or another type. Any non-string,
   * non-stringable value falls back to the language's own getName().
   *
   * @param mixed $title
   *   The raw title value from the switch-link array.
   * @param string $target_id
   *   Language code used to look up a fallback name.
   * @param array $languages
   *   Keyed array of enabled LanguageInterface objects.
   *
   * @return string
   *   A resolved plain-string title.
   */
  private function normalizeLinkTitle(mixed $title, string $target_id, array $languages): string {
    if (is_object($title) && method_exists($title, '__toString')) {
      return (string) $title;
    }
    if (is_string($title)) {
      return $title;
    }
    return isset($languages[$target_id]) ? $languages[$target_id]->getName() : '';
  }

  /**
   * Display name for a language in its own locale (e.g. العربية, हिन्दी).
   *
   * @param string $langcode
   *   BCP 47 language code.
   * @param string $fallback
   *   Label from core negotiation if Intl is unavailable.
   */
  private function nativeLanguageDisplayName(string $langcode, string $fallback): string {
    if (extension_loaded('intl') && class_exists(\Locale::class)) {
      $canonical = \Locale::canonicalize($langcode) ?: $langcode;
      $native = \Locale::getDisplayLanguage($canonical, $canonical);
      if ($native !== '') {
        return $native;
      }
    }
    return $fallback;
  }

  /**
   * Empty render array with correct cache metadata.
   *
   * @return array<string, mixed>
   */
  private function buildEmpty(): array {
    $build = [];
    $cache = BubbleableMetadata::createFromRenderArray($build)
      ->addCacheContexts(['languages:language_interface', 'config:dual_language_switch.settings']);
    $cache->applyTo($build);
    return $build;
  }

}
