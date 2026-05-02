<?php

namespace Drupal\Tests\ideas\Unit\Form;

use Drupal\ideas\Form\IdeasForm;
use Drupal\global_module\Service\FileUploadService;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * @coversDefaultClass \Drupal\ideas\Form\IdeasForm
 * @group ideas
 */
class IdeasFormTest extends UnitTestCase {

  protected $fileUploadService;
  protected $requestStack;
  protected $request;
  protected $cache;
  protected $entityTypeManager;
  protected $queueFactory;
  protected $queue;
  protected $time;
  protected $currentUser;
  protected $messenger;
  protected $form;

  protected function setUp(): void {
    parent::setUp();

    $this->fileUploadService = $this->createMock(FileUploadService::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->request = $this->createMock(Request::class);
    $this->requestStack->method('getCurrentRequest')->willReturn($this->request);

    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->queueFactory = $this->createMock(QueueFactory::class);
    $this->queue = $this->createMock(QueueInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);

    $container = new ContainerBuilder();
    $container->set('global_module.file_upload_service', $this->fileUploadService);
    $container->set('request_stack', $this->requestStack);
    $container->set('cache.default', $this->cache);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('queue', $this->queueFactory);
    $container->set('datetime.time', $this->time);
    $container->set('current_user', $this->currentUser);
    $container->set('messenger', $this->messenger);
    $container->set('string_translation', $this->getStringTranslationStub());
    
    \Drupal::setContainer($container);

    $this->form = new IdeasForm($this->fileUploadService, $this->requestStack);
  }

  /**
   * @covers ::getFormId
   */
  public function testGetFormId() {
    $this->assertEquals('ideas_form', $this->form->getFormId());
  }

  /**
   * @covers ::buildForm
   */
  public function testBuildForm() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    // Mock category options
    $this->cache->method('get')->willReturn(FALSE);
    $term_storage = $this->createMock(TermStorageInterface::class);
    $term_storage->method('loadTree')->willReturn([]);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($term_storage);

    $built_form = $this->form->buildForm($form, $form_state);

    $this->assertEquals('ideas', $built_form['#theme']);
    $this->assertArrayHasKey('first_name', $built_form);
    $this->assertArrayHasKey('author', $built_form);
    $this->assertArrayHasKey('category_idea', $built_form);
    $this->assertArrayHasKey('idea_content', $built_form);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormSuccess() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturnMap([
      ['upload_file_hidden', NULL, 'http://example.com/image.jpg'],
      ['first_name', NULL, 'My Idea'],
      ['author', NULL, 'John Doe'],
      ['category_idea', NULL, '1'],
      ['idea_content', NULL, 'Idea Body'],
    ]);

    $this->queueFactory->method('get')->with('ideas_create_queue')->willReturn($this->queue);
    $this->queue->expects($this->once())->method('createItem');
    $this->messenger->expects($this->once())->method('addStatus');

    $this->form->submitForm($form, $form_state);
  }

  /**
   * @covers ::submitForm
   */
  public function testSubmitFormMissingFile() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturnMap([
      ['upload_file_hidden', NULL, ''],
    ]);

    $this->messenger->expects($this->once())->method('addError');

    $this->form->submitForm($form, $form_state);
  }

  /**
   * @covers ::ajaxSubmitCallback
   */
  public function testAjaxSubmitCallback() {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);
    $result = $this->form->ajaxSubmitCallback($form, $form_state);
    $this->assertTrue($result['#attached']['drupalSettings']['ideas']['submissionSuccess']);
  }
}
