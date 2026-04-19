<?php

namespace Drupal\Tests\career_application\Unit\Controller;

use Drupal\career_application\Controller\CareerApplyController;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @coversDefaultClass \Drupal\career_application\Controller\CareerApplyController
 * @group career_application
 */
class CareerApplyControllerTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * @var \Drupal\Core\File\FileUrlGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileUrlGenerator;

  /**
   * @var \Drupal\career_application\Controller\CareerApplyController
   */
  protected $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->createMock(Connection::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);

    $container = new ContainerBuilder();
    $container->set('database', $this->database);
    $container->set('current_user', $this->currentUser);
    $container->set('file_url_generator', $this->fileUrlGenerator);
    $container->set('string_translation', $this->getStringTranslationStub());
    
    \Drupal::setContainer($container);

    $this->controller = new CareerApplyController();
  }

  /**
   * Tests userApplications method.
   */
  public function testUserApplications() {
    $this->currentUser->method('id')->willReturn(1);

    $select = $this->createMock(SelectInterface::class);
    $this->database->method('select')->willReturn($select);
    $select->method('fields')->willReturn($select);
    $select->method('condition')->willReturn($select);
    $select->method('orderBy')->willReturn($select);

    $record = new \stdClass();
    $record->nid = 101;
    $record->applied = '2023-01-01';

    $select->method('execute')->willReturn([$record]);

    // Mock Node::load static call
    $node = $this->getMockBuilder(Node::class)
      ->disableOriginalConstructor()
      ->getMock();
    $node->method('bundle')->willReturn('careers');
    $node->method('label')->willReturn('Job Title');
    $node->method('id')->willReturn(101);
    
    $field_item = new \stdClass();
    $field_item->value = '5 years';
    $node->method('get')->willReturn($field_item);

    $entity_storage = $this->createMock(EntityStorageInterface::class);
    $entity_storage->method('load')->with(101)->willReturn($node);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')->willReturn($entity_storage);

    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('node');
    
    \Drupal::getContainer()->set('entity_type.manager', $entity_type_manager);
    \Drupal::getContainer()->set('entity_type.repository', $entity_type_repository);

    $result = $this->controller->userApplications();

    $this->assertEquals('career_applications_list', $result['#theme']);
    $this->assertCount(1, $result['#applications']);
    $this->assertEquals('Job Title', $result['#applications'][0]['title']);
  }

  /**
   * Tests success method.
   */
  public function testSuccess() {
    $result = $this->controller->success();
    $this->assertEquals('career_application_success', $result['#theme']);
    $this->assertEquals('Application Submitted', (string) $result['#title']);
  }

  /**
   * Tests applicationDetails method.
   */
  public function testApplicationDetails() {
    $this->currentUser->method('id')->willReturn(1);

    $select = $this->createMock(SelectInterface::class);
    $statement = $this->createMock(StatementInterface::class);
    $this->database->method('select')->willReturn($select);
    $select->method('fields')->willReturn($select);
    $select->method('condition')->willReturn($select);
    $select->method('execute')->willReturn($statement);

    $record = new \stdClass();
    $record->nid = 101;
    $record->resume_fid = 202;
    $statement->method('fetchObject')->willReturn($record);

    $node = $this->getMockBuilder(Node::class)
      ->disableOriginalConstructor()
      ->getMock();
    
    $file = $this->getMockBuilder(File::class)
      ->disableOriginalConstructor()
      ->getMock();
    $file->method('getFileUri')->willReturn('public://resume.pdf');

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->method('load')->with(101)->willReturn($node);

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->with(202)->willReturn($file);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnMap([
        ['node', $node_storage],
        ['file', $file_storage],
    ]);

    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturnMap([
        [Node::class, 'node'],
        [File::class, 'file'],
    ]);
    
    \Drupal::getContainer()->set('entity_type.manager', $entity_type_manager);
    \Drupal::getContainer()->set('entity_type.repository', $entity_type_repository);
    
    $this->fileUrlGenerator->method('generateAbsoluteString')->willReturn('http://example.com/resume.pdf');

    $result = $this->controller->applicationDetails(101);

    $this->assertEquals('career_application_detail', $result['#theme']);
    $this->assertEquals($node, $result['#node']);
    $this->assertEquals('http://example.com/resume.pdf', $result['#resume_url']);
  }

  /**
   * Tests applicationDetails NotFound exception.
   */
  public function testApplicationDetailsNotFound() {
    $this->currentUser->method('id')->willReturn(1);
    $select = $this->createMock(SelectInterface::class);
    $statement = $this->createMock(StatementInterface::class);
    $this->database->method('select')->willReturn($select);
    $select->method('fields')->willReturn($select);
    $select->method('condition')->willReturn($select);
    $select->method('execute')->willReturn($statement);
    $statement->method('fetchObject')->willReturn(false);

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')->willReturn($node_storage);
    
    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_repository->method('getEntityTypeFromClass')->willReturn('node');
    
    \Drupal::getContainer()->set('entity_type.manager', $entity_type_manager);
    \Drupal::getContainer()->set('entity_type.repository', $entity_type_repository);

    $this->expectException(NotFoundHttpException::class);
    $this->controller->applicationDetails(999);
  }
}
