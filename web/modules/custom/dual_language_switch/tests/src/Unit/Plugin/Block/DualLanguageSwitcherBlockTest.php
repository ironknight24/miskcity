<?php

namespace Drupal\Tests\dual_language_switch\Unit\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\dual_language_switch\Plugin\Block\DualLanguageSwitcherBlock;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\Routing\Route;

/**
 * @coversDefaultClass \Drupal\dual_language_switch\Plugin\Block\DualLanguageSwitcherBlock
 * @group dual_language_switch
 */
class DualLanguageSwitcherBlockTest extends UnitTestCase {

  protected $languageManager;
  protected $pathMatcher;
  protected $configFactory;
  protected $routeMatch;
  protected $settings;
  protected $block;

  protected function setUp(): void {
    parent::setUp();

    $this->languageManager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $this->pathMatcher     = $this->createMock(PathMatcherInterface::class);
    $this->configFactory   = $this->createMock(ConfigFactoryInterface::class);
    $this->routeMatch      = $this->createMock(RouteMatchInterface::class);
    $this->settings        = $this->createMock(ImmutableConfig::class);

    $this->configFactory->method('get')->with('dual_language_switch.settings')->willReturn($this->settings);

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturn('/');
    $urlGenerator->method('getPathFromRoute')->willReturn('/');

    $cacheContextsManager = $this->getMockBuilder(\Drupal\Core\Cache\Context\CacheContextsManager::class)
      ->disableOriginalConstructor()
      ->getMock();
    $cacheContextsManager->method('optimizeTokens')->willReturnArgument(0);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('url_generator', $urlGenerator);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    $this->block = new DualLanguageSwitcherBlock(
      [], 'dual_language_switcher', ['admin_label' => 'Dual language switcher'],
      $this->languageManager, $this->pathMatcher, $this->configFactory, $this->routeMatch,
    );
  }

  private function mockLanguage(string $id, string $name = ''): LanguageInterface {
    $lang = $this->createMock(LanguageInterface::class);
    $lang->method('getId')->willReturn($id);
    $lang->method('getName')->willReturn($name ?: $id);
    return $lang;
  }

  private function buildSwitch(string $method_id, array $links): object {
    $s = new \stdClass();
    $s->method_id = $method_id;
    $s->links = $links;
    return $s;
  }

  private function setupHappyPath(string $currentLangId, string $secondary, array $extraLangs = []): void {
    $this->settings->method('get')->with('secondary_langcode')->willReturn($secondary);
    $en = $this->mockLanguage('en', 'English');
    $fr = $this->mockLanguage('fr', 'French');
    $langs = array_merge(['en' => $en, 'fr' => $fr], $extraLangs);
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn($langs);
    $this->languageManager->method('getCurrentLanguage')->willReturn($this->mockLanguage($currentLangId));
    $this->pathMatcher->method('isFrontPage')->willReturn(TRUE);
  }

  /** @covers ::blockAccess */
  public function testBlockAccessAllowedWhenMultilingual(): void {
    $this->languageManager->method('isMultilingual')->willReturn(TRUE);
    $method = new \ReflectionMethod($this->block, 'blockAccess');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->block, $this->createMock(AccountInterface::class));
    $this->assertInstanceOf(AccessResult::class, $result);
    $this->assertTrue($result->isAllowed());
  }

  /** @covers ::blockAccess */
  public function testBlockAccessForbiddenWhenNotMultilingual(): void {
    $this->languageManager->method('isMultilingual')->willReturn(FALSE);
    $method = new \ReflectionMethod($this->block, 'blockAccess');
    $method->setAccessible(TRUE);
    $result = $method->invoke($this->block, $this->createMock(AccountInterface::class));
    $this->assertInstanceOf(AccessResult::class, $result);
    $this->assertTrue($result->isForbidden());
  }

  /** @covers ::build */
  public function testBuildReturnsEmptyWhenNoSecondaryConfigured(): void {
    $this->settings->method('get')->with('secondary_langcode')->willReturn('');
    $build = $this->block->build();
    $this->assertArrayNotHasKey('#theme', $build);
    $this->assertArrayHasKey('#cache', $build);
  }

  /** @covers ::build */
  public function testBuildReturnsEmptyWhenSecondaryNotInEnabledLanguages(): void {
    $this->settings->method('get')->with('secondary_langcode')->willReturn('fr');
    $en = $this->mockLanguage('en');
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn(['en' => $en]);
    $this->assertArrayNotHasKey('#theme', $this->block->build());
  }

  /** @covers ::build */
  public function testBuildReturnsEmptyWhenSecondaryEqualsDefault(): void {
    $this->settings->method('get')->with('secondary_langcode')->willReturn('en');
    $en = $this->mockLanguage('en');
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn(['en' => $en]);
    $this->assertArrayNotHasKey('#theme', $this->block->build());
  }

  /** @covers ::build */
  public function testBuildReturnsEmptyWhenSwitchLinksUnavailable(): void {
    $this->setupHappyPath('en', 'fr');
    $this->languageManager->method('getLanguageSwitchLinks')->willReturn(NULL);
    $this->assertArrayNotHasKey('#theme', $this->block->build());
  }

  /** @covers ::build @covers ::resolveTargetLanguage */
  public function testBuildFromDefaultToSecondary(): void {
    $this->setupHappyPath('en', 'fr');
    $this->languageManager->method('getLanguageSwitchLinks')
      ->willReturn($this->buildSwitch('language-url', ['fr' => ['title' => 'French', 'url' => NULL]]));
    $build = $this->block->build();
    $this->assertEquals('links__language_block', $build['#theme']);
    $this->assertArrayHasKey('fr', $build['#links']);
    $this->assertArrayNotHasKey('en', $build['#links']);
  }

  /** @covers ::build @covers ::resolveTargetLanguage */
  public function testBuildFromSecondaryToDefault(): void {
    $this->setupHappyPath('fr', 'fr');
    $this->languageManager->method('getLanguageSwitchLinks')
      ->willReturn($this->buildSwitch('language-url', ['en' => ['title' => 'English', 'url' => NULL]]));
    $build = $this->block->build();
    $this->assertEquals('links__language_block', $build['#theme']);
    $this->assertArrayHasKey('en', $build['#links']);
    $this->assertArrayNotHasKey('fr', $build['#links']);
  }

  /** @covers ::build @covers ::resolveTargetLanguage */
  public function testBuildFromThirdLanguageToDefault(): void {
    $de = $this->mockLanguage('de', 'German');
    $this->setupHappyPath('de', 'fr', ['de' => $de]);
    $this->languageManager->method('getLanguageSwitchLinks')
      ->willReturn($this->buildSwitch('language-url', ['en' => ['title' => 'English', 'url' => NULL]]));
    $build = $this->block->build();
    $this->assertArrayHasKey('en', $build['#links']);
    $this->assertArrayNotHasKey('fr', $build['#links']);
  }

  /** @covers ::build @covers ::resolveUrl */
  public function testBuildUsesFromFrontWhenOnFrontPage(): void {
    $this->setupHappyPath('en', 'fr');
    $this->languageManager->method('getLanguageSwitchLinks')
      ->willReturn($this->buildSwitch('language-url', ['fr' => ['title' => 'French', 'url' => NULL]]));
    $this->assertArrayHasKey('#theme', $this->block->build());
  }

  /** @covers ::build @covers ::resolveUrl */
  public function testBuildUsesFromFrontWhenNoRouteObject(): void {
    $this->settings->method('get')->with('secondary_langcode')->willReturn('fr');
    $en = $this->mockLanguage('en', 'English');
    $fr = $this->mockLanguage('fr', 'French');
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn(['en' => $en, 'fr' => $fr]);
    $this->languageManager->method('getCurrentLanguage')->willReturn($en);
    $this->languageManager->method('getLanguageSwitchLinks')
      ->willReturn($this->buildSwitch('language-url', ['fr' => ['title' => 'French', 'url' => NULL]]));
    $this->pathMatcher->method('isFrontPage')->willReturn(FALSE);
    $this->routeMatch->method('getRouteObject')->willReturn(NULL);
    $this->assertArrayHasKey('#theme', $this->block->build());
  }

  /** @covers ::build @covers ::resolveUrl */
  public function testBuildUsesFromRouteWhenNotFrontPage(): void {
    $this->settings->method('get')->with('secondary_langcode')->willReturn('fr');
    $en = $this->mockLanguage('en', 'English');
    $fr = $this->mockLanguage('fr', 'French');
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn(['en' => $en, 'fr' => $fr]);
    $this->languageManager->method('getCurrentLanguage')->willReturn($en);
    $this->languageManager->method('getLanguageSwitchLinks')
      ->willReturn($this->buildSwitch('language-url', ['fr' => ['title' => 'French', 'url' => NULL]]));
    $this->pathMatcher->method('isFrontPage')->willReturn(FALSE);
    $this->routeMatch->method('getRouteObject')->willReturn($this->createMock(Route::class));
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.canonical');
    $this->routeMatch->method('getRawParameters')->willReturn(new ParameterBag(['node' => 1]));
    $build = $this->block->build();
    $this->assertArrayHasKey('#theme', $build);
    $this->assertArrayHasKey('fr', $build['#links']);
  }

  /** @covers ::build */
  public function testBuildContainsCacheContexts(): void {
    $this->setupHappyPath('en', 'fr');
    $this->languageManager->method('getLanguageSwitchLinks')
      ->willReturn($this->buildSwitch('language-url', ['fr' => ['title' => 'French', 'url' => NULL]]));
    $contexts = $this->block->build()['#cache']['contexts'] ?? [];
    $this->assertContains('languages:language_interface', $contexts);
    $this->assertContains('url.path', $contexts);
    $this->assertContains('url.query_args', $contexts);
    $this->assertContains('url.site', $contexts);
  }

  /** @covers ::build */
  public function testBuildContainsCacheTags(): void {
    $this->setupHappyPath('en', 'fr');
    $this->languageManager->method('getLanguageSwitchLinks')
      ->willReturn($this->buildSwitch('language-url', ['fr' => ['title' => 'French', 'url' => NULL]]));
    $tags = $this->block->build()['#cache']['tags'] ?? [];
    $this->assertContains('config:configurable_language_list', $tags);
    $this->assertContains('config:dual_language_switch.settings', $tags);
  }

}