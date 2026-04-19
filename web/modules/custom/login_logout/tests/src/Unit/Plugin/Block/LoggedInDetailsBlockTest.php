<?php

namespace Drupal\Tests\login_logout\Unit\Plugin\Block;

use Drupal\login_logout\Plugin\Block\LoggedInDetailsBlock;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\login_logout\Plugin\Block\LoggedInDetailsBlock
 * @group login_logout
 */
class LoggedInDetailsBlockTest extends UnitTestCase {

  protected $currentUser;
  protected $requestStack;
  protected $request;
  protected $session;
  protected $block;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->request = $this->createMock(Request::class);
    $this->session = $this->createMock(SessionInterface::class);

    $this->request->method('getSession')->willReturn($this->session);
    $this->requestStack->method('getCurrentRequest')->willReturn($this->request);

    $container = new ContainerBuilder();
    $container->set('current_user', $this->currentUser);
    $container->set('request_stack', $this->requestStack);
    \Drupal::setContainer($container);

    $configuration = [];
    $plugin_id = 'logged_in_details';
    $plugin_definition = ['admin_label' => 'Logged in details'];

    $this->block = new LoggedInDetailsBlock(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $this->currentUser
    );
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(LoggedInDetailsBlock::class, $this->block);
  }

  /**
   * @covers ::create
   */
  public function testCreate() {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->with('current_user')->willReturn($this->currentUser);

    $block = LoggedInDetailsBlock::create($container, [], 'id', []);
    $this->assertInstanceOf(LoggedInDetailsBlock::class, $block);
  }

  /**
   * @covers ::getCacheMaxAge
   */
  public function testGetCacheMaxAge() {
    $this->assertEquals(0, $this->block->getCacheMaxAge());
  }

  /**
   * @covers ::build
   */
  public function testBuildWithUserData() {
    $user_data = [
      'firstName' => 'John',
      'lastName' => 'Doe',
      'emailId' => 'john@example.com',
      'profilePic' => 'https://example.com/pic.jpg',
    ];
    $this->session->method('get')->with('api_redirect_result')->willReturn($user_data);

    $build = $this->block->build();

    $this->assertEquals('logged_in_details_block', $build['#theme']);
    $this->assertEquals('John Doe', $build['#display_name']);
    $this->assertEquals('john@example.com', $build['#email']);
    $this->assertEquals('https://example.com/pic.jpg', $build['#avatar_url']);
  }

  /**
   * @covers ::build
   */
  public function testBuildWithoutUserData() {
    $this->session->method('get')->with('api_redirect_result')->willReturn(NULL);

    $build = $this->block->build();

    $this->assertEmpty($build);
  }

  /**
   * @covers ::build
   */
  public function testBuildWithDefaultAvatar() {
    $user_data = [
      'firstName' => 'Jane',
      'lastName' => 'Doe',
      'emailId' => 'jane@example.com',
      'profilePic' => null,
    ];
    $this->session->method('get')->with('api_redirect_result')->willReturn($user_data);

    $build = $this->block->build();

    $this->assertEquals('/themes/custom/engage_theme/images/Profile/profile_pic.png', $build['#avatar_url']);
    
    // Test with "null" string
    $user_data['profilePic'] = "null";
    $this->session->method('get')->with('api_redirect_result')->willReturn($user_data);
    $build = $this->block->build();
    $this->assertEquals('/themes/custom/engage_theme/images/Profile/profile_pic.png', $build['#avatar_url']);

    // Test with empty string
    $user_data['profilePic'] = "";
    $this->session->method('get')->with('api_redirect_result')->willReturn($user_data);
    $build = $this->block->build();
    $this->assertEquals('/themes/custom/engage_theme/images/Profile/profile_pic.png', $build['#avatar_url']);
  }
}
