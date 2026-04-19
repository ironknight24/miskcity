<?php

namespace Drupal\Tests\city_map\Unit\Form;

use Drupal\city_map\Form\CityMapForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\city_map\Form\CityMapForm
 * @group city_map
 */
class CityMapFormTest extends UnitTestCase {

  /**
   * @var \Drupal\city_map\Form\CityMapForm
   */
  protected $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->form = new CityMapForm();
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('city_map_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertEquals('city_map', $built_form['#theme']);
    $this->assertArrayHasKey('library', $built_form['#attached']);
    $this->assertContains('city_map/city-map-library', $built_form['#attached']['library']);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    
    // The submitForm method is empty, so we just call it to ensure no errors and coverage.
    $this->form->submitForm($form, $form_state);
    $this->assertTrue(true);
  }

}
