<?php

namespace Drupal\Tests\login_logout\Unit\Access;

use Drupal\login_logout\Access\ForgotPasswordAccessCheck;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Url;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Routing\UrlGeneratorInterface;

/**
 * @coversDefaultClass \Drupal\login_logout\Access\ForgotPasswordAccessCheck
 * @group login_logout
 */
class ForgotPasswordAccessCheckTest extends UnitTestCase {

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

    $this->accessCheck = new ForgotPasswordAccessCheck();
  }

  /**
   * @covers ::access
   */
  public function testAccessAuthenticated() {
    $this->account->method('isAuthenticated')->willReturn(TRUE);
    
    // Url::fromRoute('<front>')->toString() will call url_generator->generateFromRoute('<front>', ...)
    $this->urlGenerator->method('generateFromRoute')
      ->with('<front>')
      ->willReturn('/');

    $result = $this->accessCheck->access($this->account);

    $this->assertTrue($result->isForbidden());
    $this->assertEquals(0, $result->getCacheMaxAge());
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
