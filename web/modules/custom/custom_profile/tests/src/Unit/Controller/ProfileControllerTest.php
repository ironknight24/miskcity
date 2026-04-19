<?php

namespace Drupal\Tests\custom_profile\Unit\Controller;

use Drupal\custom_profile\Controller\ProfileController;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormBuilderInterface;

/**
 * @coversDefaultClass \Drupal\custom_profile\Controller\ProfileController
 * @group custom_profile
 */
class ProfileControllerTest extends UnitTestCase {

  /**
   * @covers ::myAccount
   */
  public function testMyAccount() {
    $form_builder = $this->createMock(FormBuilderInterface::class);
    $form_builder->method('getForm')->willReturn(['#markup' => 'Form']);

    $container = new ContainerBuilder();
    $container->set('form_builder', $form_builder);
    \Drupal::setContainer($container);

    $controller = new ProfileController();
    $result = $controller->myAccount();

    $this->assertEquals('custom_profile', $result['#theme']);
    $this->assertEquals(['#markup' => 'Form'], $result['#profile_picture_form']);
    $this->assertEquals(['#markup' => 'Form'], $result['#form']);
    $this->assertContains('custom_profile/profile_assets', $result['#attached']['library']);
  }

}
