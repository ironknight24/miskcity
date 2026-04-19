<?php

namespace Drupal\Tests\smart_services\Unit\Controller;

use Drupal\smart_services\Controller\SmartServicesController;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * @coversDefaultClass \Drupal\smart_services\Controller\SmartServicesController
 * @group smart_services
 */
class SmartServicesControllerTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Render\RendererInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $renderer;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\smart_services\Controller\SmartServicesController
   */
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->renderer = $this->createMock(RendererInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $container = new ContainerBuilder();
    $container->set('renderer', $this->renderer);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('string_translation', $this->getStringTranslationStub());
    
    \Drupal::setContainer($container);

    $this->controller = new SmartServicesController($this->renderer);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = \Drupal::getContainer();
    $controller = SmartServicesController::create($container);
    $this->assertInstanceOf(SmartServicesController::class, $controller);
  }

  /**
   * @covers ::landing
   */
  public function testLanding() {
    $term = $this->createMock(Term::class);
    $term->method('id')->willReturn(1);

    $term_storage = $this->createMock(TermStorageInterface::class);
    $term_storage->method('loadTree')->willReturn([$term]);
    $term_storage->method('loadChildren')->with(1)->willReturn([$this->createMock(Term::class)]);

    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($term_storage);

    $result = $this->controller->landing();

    $this->assertEquals('smart_services_list', $result['#theme']);
    $this->assertCount(1, $result['#terms']);
    $this->assertContains('core/drupal.ajax', $result['#attached']['library']);
  }

  /**
   * @covers ::termView
   */
  public function testTermView() {
    $tid = 1;
    $request = new Request();

    $term = $this->getMockBuilder(Term::class)
      ->disableOriginalConstructor()
      ->getMock();
    $term->method('bundle')->willReturn('smart_services');
    $term->method('id')->willReturn($tid);
    
    $parent_field = $this->getMockBuilder('\stdClass')
      ->addMethods(['getValue'])
      ->getMock();
    $parent_field->method('getValue')->willReturn([['target_id' => 0]]);
    $term->method('get')->with('parent')->willReturn($parent_field);

    // Mock Term::load static call
    $term_storage = $this->createMock(TermStorageInterface::class);
    $term_storage->method('load')->with($tid)->willReturn($term);
    $term_storage->method('loadChildren')->with($tid)->willReturn([]);

    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($term_storage);

    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('taxonomy_term');
    \Drupal::getContainer()->set('entity_type.repository', $entity_type_repository);

    $result = $this->controller->termView($tid, $request);

    $this->assertIsArray($result);
    $this->assertEquals('smart_services_list', $result['#theme']);
    $this->assertEquals($term, $result['#current_term']);
  }

  /**
   * @covers ::termView
   */
  public function testTermViewAjax() {
    $tid = 1;
    $request = new Request();
    $request->headers->set('X-Requested-With', 'XMLHttpRequest');

    $term = $this->getMockBuilder(Term::class)
      ->disableOriginalConstructor()
      ->getMock();
    $term->method('bundle')->willReturn('smart_services');
    
    $parent_field = $this->getMockBuilder('\stdClass')
      ->addMethods(['getValue'])
      ->getMock();
    $parent_field->method('getValue')->willReturn([]);
    $term->method('get')->with('parent')->willReturn($parent_field);

    $term_storage = $this->createMock(TermStorageInterface::class);
    $term_storage->method('load')->with($tid)->willReturn($term);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($term_storage);

    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('taxonomy_term');
    \Drupal::getContainer()->set('entity_type.repository', $entity_type_repository);

    $this->renderer->method('renderRoot')->willReturn('<div>Rendered Content</div>');

    $response = $this->controller->termView($tid, $request);

    $this->assertInstanceOf(AjaxResponse::class, $response);
    $commands = $response->getCommands();
    $this->assertEquals('insert', $commands[0]['command']);
    $this->assertEquals('#smart-services-wrapper', $commands[0]['selector']);
  }

  /**
   * @covers ::getTermTitle
   */
  public function testGetTermTitle() {
    $tid = 1;
    $term = $this->getMockBuilder(Term::class)
      ->disableOriginalConstructor()
      ->getMock();
    $term->method('label')->willReturn('My Term Label');

    $term_storage = $this->createMock(TermStorageInterface::class);
    $term_storage->method('load')->with($tid)->willReturn($term);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($term_storage);

    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('taxonomy_term');
    \Drupal::getContainer()->set('entity_type.repository', $entity_type_repository);

    $title = $this->controller->getTermTitle($tid);
    $this->assertEquals('My Term Label', $title);
  }
}
