<?php

namespace Drupal\Tests\dual_language_switch\Unit\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\dual_language_switch\Form\DualLanguageSettingsForm;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\dual_language_switch\Form\DualLanguageSettingsForm
 * @group dual_language_switch
 */
class DualLanguageSettingsFormTest extends UnitTestCase {

  protected $languageManager;
  protected $configFactory;
  protected $typedConfigManager;
  protected $immutableConfig;
  protected $mutableConfig;
  protected $messenger;
  protected $form;

  protected function setUp(): void {
    parent::setUp();

    $this->languageManager    = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $this->configFactory      = $this->createMock(ConfigFactoryInterface::class);
    $this->typedConfigManager = $this->createMock(TypedConfigManagerInterface::class);
    $this->immutableConfig    = $this->createMock(ImmutableConfig::class);
    $this->mutableConfig      = $this->createMock(Config::class);
    $this->messenger          = $this->createMock(MessengerInterface::class);

    $this->configFactory->method('get')
      ->with('dual_language_switch.settings')->willReturn($this->immutableConfig);
    $this->configFactory->method('getEditable')
      ->with('dual_language_switch.settings')->willReturn($this->mutableConfig);

    $this->mutableConfig->method('set')->willReturnSelf();
    $this->mutableConfig->method('save')->willReturnSelf();

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('messenger', $this->messenger);
    \Drupal::setContainer($container);

    $this->form = new DualLanguageSettingsForm(
      $this->configFactory,
      $this->typedConfigManager,
      $this->languageManager,
    );
  }

  private function mockLanguage(string $id, string $name): LanguageInterface {
    $lang = $this->createMock(LanguageInterface::class);
    $lang->method('getId')->willReturn($id);
    $lang->method('getName')->willReturn($name);
    return $lang;
  }

  /** @covers ::getFormId */
  public function testGetFormId(): void {
    $this->assertEquals('dual_language_switch_settings', $this->form->getFormId());
  }

  /** @covers ::buildForm */
  public function testBuildFormWithMultipleLanguages(): void {
    $en = $this->mockLanguage('en', 'English');
    $fr = $this->mockLanguage('fr', 'French');
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn(['en' => $en, 'fr' => $fr]);
    $this->immutableConfig->method('get')->with('secondary_langcode')->willReturn('');

    $form_state = $this->createMock(FormStateInterface::class);
    $built = $this->form->buildForm([], $form_state);

    $this->assertArrayHasKey('secondary_langcode', $built);
    $this->assertArrayHasKey('fr', $built['secondary_langcode']['#options']);
    $this->assertArrayNotHasKey('en', $built['secondary_langcode']['#options']);
    $this->assertEmpty($built['secondary_langcode']['#disabled'] ?? FALSE);
  }

  /** @covers ::buildForm */
  public function testBuildFormDisabledWhenOnlyDefaultLanguage(): void {
    $en = $this->mockLanguage('en', 'English');
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn(['en' => $en]);
    $this->immutableConfig->method('get')->with('secondary_langcode')->willReturn('');

    $form_state = $this->createMock(FormStateInterface::class);
    $built = $this->form->buildForm([], $form_state);

    $this->assertTrue($built['secondary_langcode']['#disabled']);
    $this->assertEmpty($built['secondary_langcode']['#options']);
  }

  /** @covers ::validateForm */
  public function testValidateFormAllowsEmptySecondary(): void {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('secondary_langcode')->willReturn('');
    $form_state->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->form->validateForm($form, $form_state);
  }

  /** @covers ::validateForm */
  public function testValidateFormRejectsSecondaryEqualToDefault(): void {
    $en = $this->mockLanguage('en', 'English');
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn(['en' => $en]);

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('secondary_langcode')->willReturn('en');
    $form_state->expects($this->atLeastOnce())->method('setErrorByName')
      ->with('secondary_langcode', $this->anything());

    $form = [];
    $this->form->validateForm($form, $form_state);
  }

  /** @covers ::validateForm */
  public function testValidateFormRejectsNonExistentLanguage(): void {
    $en = $this->mockLanguage('en', 'English');
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn(['en' => $en]);

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('secondary_langcode')->willReturn('fr');
    $form_state->expects($this->atLeastOnce())->method('setErrorByName')
      ->with('secondary_langcode', $this->anything());

    $form = [];
    $this->form->validateForm($form, $form_state);
  }

  /** @covers ::validateForm */
  public function testValidateFormPassesWithValidSecondary(): void {
    $en = $this->mockLanguage('en', 'English');
    $fr = $this->mockLanguage('fr', 'French');
    $this->languageManager->method('getDefaultLanguage')->willReturn($en);
    $this->languageManager->method('getLanguages')->willReturn(['en' => $en, 'fr' => $fr]);

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('secondary_langcode')->willReturn('fr');
    $form_state->expects($this->never())->method('setErrorByName');

    $form = [];
    $this->form->validateForm($form, $form_state);
  }

  /** @covers ::submitForm */
  public function testSubmitFormSavesConfig(): void {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('secondary_langcode')->willReturn('fr');

    $this->mutableConfig->expects($this->once())->method('set')
      ->with('secondary_langcode', 'fr')->willReturnSelf();
    $this->mutableConfig->expects($this->once())->method('save');

    $this->messenger->expects($this->once())->method('addStatus');

    $form = [];
    $this->form->submitForm($form, $form_state);
  }

}