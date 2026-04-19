<?php

namespace Drupal\Tests\login_logout\Unit\Access;

use Drupal\login_logout\Access\UserRegisterAccessCheck;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Routing\UrlGeneratorInterface;

/**
 * @coversDefaultClass \Drupal\login_logout\Access\UserRegisterAccessCheck
 * @group login_logout
 */
class UserRegisterAccessCheckTest extends UnitTestCase {

  protected $account;
  protected $urlGenerator;
  protected $accessCheck;

  protected function setUp(): void {
    parent::setUp();

    $this->account = $this->createMock(AccountInterface::class);
    $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

    $container = new ContainerBuilder();
    $container->set('url_generator', $this->urlGenerator);
    \Drupal::setContainer($container);

    $this->accessCheck = new UserRegisterAccessCheck();
  }

  /**
   * @covers ::access
   */
  public function testAccessAuthenticated() {
    $this->account->method('isAuthenticated')->willReturn(TRUE);
    
    $this->urlGenerator->method('generateFromRoute')
      ->with('<front>')
      ->willReturn('/');

    // The code calls $response->send() which might output headers. 
    // In Unit tests this usually works but might print to console.
    $result = $this->accessCheck->access($this->account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * @covers ::access
   */
  public function testAccessAnonymous() {
    $this->account->method('isAuthenticated')->willReturn(FALSE);

    $result = $this->accessCheck->access($this->account);

    $this->assertTrue($result->isAllowed());
  }

}
