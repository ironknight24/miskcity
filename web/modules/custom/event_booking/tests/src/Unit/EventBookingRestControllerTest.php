<?php

namespace Drupal\Tests\event_booking\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\court_booking\CommerceCheckoutRestService;
use Drupal\event_booking\Controller\EventBookingRestController;
use Drupal\event_booking\EventBookingApiService;
use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Unit tests for EventBookingRestController.
 */
class EventBookingRestControllerTest extends TestCase {

  public function testVerifyPortalUserRequiresPortalUserId(): void {
    $api = $this->createMock(EventBookingApiService::class);
    $checkout = $this->createMock(CommerceCheckoutRestService::class);
    $controller = new EventBookingRestController($api, $checkout);

    $request = new Request([], [], [], [], [], [], json_encode([]));

    $this->expectException(BadRequestHttpException::class);
    $controller->verifyPortalUser($request);
  }

  public function testVerifyPortalUserDelegatesToService(): void {
    $api = $this->createMock(EventBookingApiService::class);
    $checkout = $this->createMock(CommerceCheckoutRestService::class);
    $controller = new EventBookingRestController($api, $checkout);

    $account = $this->createMock(AccountInterface::class);
    $this->setControllerCurrentUser($controller, $account);

    $api->expects($this->once())
      ->method('verifyPortalUser')
      ->with($account, 'abc123')
      ->willReturn(['status' => 200, 'data' => ['ok' => TRUE]]);

    $request = new Request([], [], [], [], [], [], json_encode(['portal_user_id' => 'abc123']));

    $response = $controller->verifyPortalUser($request);
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode());
  }

  public function testDecodeJsonRejectsInvalidBody(): void {
    $api = $this->createMock(EventBookingApiService::class);
    $checkout = $this->createMock(CommerceCheckoutRestService::class);
    $controller = new EventBookingRestController($api, $checkout);

    $request = new Request([], [], [], [], [], [], '""not-an-object""');
    $this->expectException(BadRequestHttpException::class);
    $this->invokePrivateMethod($controller, 'decodeJson', [$request]);
  }

  public function testExtractListParamsEnforcesBounds(): void {
    $api = $this->createMock(EventBookingApiService::class);
    $checkout = $this->createMock(CommerceCheckoutRestService::class);
    $controller = new EventBookingRestController($api, $checkout);

    $request = new Request(['page' => -1, 'limit' => 500, 'q' => '  foo  ']);
    $params = $this->invokePrivateMethod($controller, 'extractListParams', [$request]);

    $this->assertSame(0, $params['page']);
    $this->assertSame(50, $params['limit']);
    $this->assertSame('foo', $params['q']);
  }

  private function setControllerCurrentUser(EventBookingRestController $controller, AccountInterface $account): void {
    $property = new \ReflectionProperty(\Drupal\Core\Controller\ControllerBase::class, 'currentUser');
    $property->setAccessible(TRUE);
    $property->setValue($controller, $account);
  }

  /**
   * @param array<int, mixed> $arguments
   */
  private function invokePrivateMethod(object $instance, string $method_name, array $arguments = []): mixed {
    $method = new \ReflectionMethod($instance, $method_name);
    $method->setAccessible(TRUE);
    return $method->invokeArgs($instance, $arguments);
  }

}

