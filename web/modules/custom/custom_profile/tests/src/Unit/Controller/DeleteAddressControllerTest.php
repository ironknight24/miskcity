<?php

namespace Drupal\Tests\custom_profile\Unit\Controller;

use Drupal\custom_profile\Controller\DeleteAddressController;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Access\AccessResultInterface;

/**
 * @coversDefaultClass \Drupal\custom_profile\Controller\DeleteAddressController
 * @group custom_profile
 */
class DeleteAddressControllerTest extends UnitTestCase {

  protected $entityTypeManager;
  protected $entityTypeRepository;
  protected $nodeStorage;
  protected $controller;

  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeRepository = $this->createMock(EntityTypeRepositoryInterface::class);
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($this->nodeStorage);
    $this->entityTypeRepository->method('getEntityTypeFromClass')->willReturn('node');

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('entity_type.repository', $this->entityTypeRepository);
    \Drupal::setContainer($container);

    $this->controller = new class extends DeleteAddressController {
      public function isAddressNodeProxy(?Node $node): bool {
        return $this->isAddressNode($node);
      }

      public function canDeleteAddressProxy(Node $node, AccountInterface $account): bool {
        return $this->canDeleteAddress($node, $account);
      }
    };
  }

  /**
   * @covers ::access
   */
  public function testAccessForbidden() {
    $this->nodeStorage->method('load')->willReturn(NULL);
    $account = $this->createMock(AccountInterface::class);
    
    $result = $this->controller->access(123, $account);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::access
   */
  public function testAccessDeleteAny() {
    $node = $this->getMockBuilder(Node::class)->disableOriginalConstructor()->getMock();
    $node->method('bundle')->willReturn('add_address');
    $this->nodeStorage->method('load')->willReturn($node);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('delete any add_address content')->willReturn(TRUE);

    $result = $this->controller->access(123, $account);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::access
   */
  public function testAccessDeleteOwn() {
    $node = $this->getMockBuilder(Node::class)->disableOriginalConstructor()->getMock();
    $node->method('bundle')->willReturn('add_address');
    $node->method('getOwnerId')->willReturn(1);
    $this->nodeStorage->method('load')->willReturn($node);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnMap([
      ['delete any add_address content', FALSE],
      ['delete own add_address content', TRUE],
    ]);
    $account->method('id')->willReturn(1);

    $result = $this->controller->access(123, $account);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::delete
   */
  public function testDeleteNotFound() {
    $this->nodeStorage->method('load')->willReturn(NULL);
    $request = new Request();
    
    $response = $this->controller->delete(123, $request);
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * @covers ::delete
   */
  public function testDeleteSuccess() {
    $node = $this->getMockBuilder(Node::class)->disableOriginalConstructor()->getMock();
    $node->method('bundle')->willReturn('add_address');
    $node->expects($this->once())->method('delete');
    $this->nodeStorage->method('load')->willReturn($node);

    $request = new Request();
    $response = $this->controller->delete(123, $request);
    
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals(json_encode(['status' => 'success']), $response->getContent());
  }

  /**
   * @covers ::isAddressNode
   * @covers ::canDeleteAddress
   */
  public function testAccessHelpers() {
    $node = $this->getMockBuilder(Node::class)->disableOriginalConstructor()->getMock();
    $node->method('bundle')->willReturn('add_address');
    $node->method('getOwnerId')->willReturn(7);

    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnMap([
      ['delete any add_address content', FALSE],
      ['delete own add_address content', TRUE],
    ]);
    $account->method('id')->willReturn(7);

    $this->assertTrue($this->controller->isAddressNodeProxy($node));
    $this->assertFalse($this->controller->isAddressNodeProxy(NULL));
    $this->assertTrue($this->controller->canDeleteAddressProxy($node, $account));
  }
}
