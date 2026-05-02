<?php

namespace Drupal\Tests\event_booking\Unit;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\commerce_stock\StockServiceManagerInterface;
use Drupal\court_booking\CourtBookingApiService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\event_booking\EventBookingApiService;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\event_booking\Portal\PortalUserClientInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for EventBookingApiService.
 *
 * These tests intentionally exercise behaviour via public methods only,
 * mirroring the existing API shape so future refactors remain safe.
 */
class EventBookingApiServiceTest extends UnitTestCase {

  private EventBookingApiService $service;

  /** @var \PHPUnit\Framework\MockObject\MockObject&CartManagerInterface */
  private MockObject $cartManager;

  /** @var \PHPUnit\Framework\MockObject\MockObject&CartProviderInterface */
  private MockObject $cartProvider;

  /** @var \PHPUnit\Framework\MockObject\MockObject&OrderRefreshInterface */
  private MockObject $orderRefresh;

  /** @var \PHPUnit\Framework\MockObject\MockObject&EntityTypeManagerInterface */
  private MockObject $entityTypeManager;

  /** @var \PHPUnit\Framework\MockObject\MockObject&EntityFieldManagerInterface */
  private MockObject $entityFieldManager;

  /** @var \PHPUnit\Framework\MockObject\MockObject&ConfigFactoryInterface */
  private MockObject $configFactory;

  /** @var \PHPUnit\Framework\MockObject\MockObject&GlobalVariablesService */
  private MockObject $globalVariables;

  /** @var \PHPUnit\Framework\MockObject\MockObject&FileUrlGeneratorInterface */
  private MockObject $fileUrlGenerator;

  /** @var \PHPUnit\Framework\MockObject\MockObject&StockServiceManagerInterface */
  private MockObject $stockServiceManager;

  /** @var \PHPUnit\Framework\MockObject\MockObject&LoggerInterface */
  private MockObject $logger;

  /** @var \PHPUnit\Framework\MockObject\MockObject&CourtBookingApiService */
  private MockObject $courtBookingApi;

  /** @var \PHPUnit\Framework\MockObject\MockObject&PortalUserClientInterface */
  private MockObject $portalUserClient;

  protected function setUp(): void {
    parent::setUp();

    $this->cartManager = $this->createMock(CartManagerInterface::class);
    $this->cartProvider = $this->createMock(CartProviderInterface::class);
    $this->orderRefresh = $this->createMock(OrderRefreshInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->globalVariables = $this->createMock(GlobalVariablesService::class);
    $this->fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $this->stockServiceManager = $this->createMock(StockServiceManagerInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->courtBookingApi = $this->createMock(CourtBookingApiService::class);
    $this->portalUserClient = $this->createMock(PortalUserClientInterface::class);

    $this->service = new EventBookingApiService(
      $this->cartManager,
      $this->cartProvider,
      $this->orderRefresh,
      $this->entityTypeManager,
      $this->entityFieldManager,
      $this->configFactory,
      $this->globalVariables,
      $this->fileUrlGenerator,
      $this->stockServiceManager,
      $this->logger,
      $this->courtBookingApi,
      $this->portalUserClient,
    );
    $this->service->setStringTranslation($this->getStringTranslationStub());
  }

  public function testVerifyPortalUserRequiresAuthentication(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(FALSE);

    $result = $this->service->verifyPortalUser($account, 'some-id');

    $this->assertSame(401, $result['status']);
    $this->assertArrayHasKey('message', $result['data']);
  }

  public function testVerifyPortalUserMissingEmail(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('id')->willReturn(1);

    $user_storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('user')
      ->willReturn($user_storage);
    $user_storage->method('load')->with(1)->willReturn(NULL);
    $account->method('getEmail')->willReturn(NULL);

    $result = $this->service->verifyPortalUser($account, 'some-id');

    $this->assertSame(400, $result['status']);
  }

  public function testResolvePortalUserContextMissingPortalConfig(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('id')->willReturn(1);

    $user_storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('user')
      ->willReturn($user_storage);
    $user = $this->createMock(UserInterface::class);
    $user->method('getEmail')->willReturn('user@example.com');
    $user_storage->method('load')->with(1)->willReturn($user);

    $this->portalUserClient
      ->method('isConfigured')
      ->willReturn(FALSE);

    $result = $this->service->resolvePortalUserContext($account);

    $this->assertSame(503, $result['status']);
  }

  public function testGetCartPayloadReturnsNotFoundWhenNoCart(): void {
    $account = $this->createMock(AccountInterface::class);

    $store = $this->createMock(StoreInterface::class);
    $store_storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $store_storage->method('load')->with('2')->willReturn($store);
    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['commerce_store', $store_storage],
      ]);

    $this->configFactory->method('get')
      ->with('event_booking.settings')
      ->willReturn($this->createConfigMock([
        'commerce_store_id' => '2',
        'order_type_id' => 'default',
      ]));

    $this->cartProvider
      ->method('getCart')
      ->willReturn(NULL);

    $result = $this->service->getCartPayload($account);

    $this->assertSame(404, $result['status']);
  }

  public function testGetTicketVariationPricingHappyPath(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);

    $store = $this->createMock(StoreInterface::class);
    $store->method('id')->willReturn('2');
    $store_storage = $this->createMock(\Drupal\Core\Entity\EntityStorageInterface::class);
    $store_storage->method('load')->with('2')->willReturn($store);
    $this->entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['commerce_store', $store_storage],
      ]);
    $this->configFactory->method('get')
      ->with('event_booking.settings')
      ->willReturn($this->createConfigMock([
        'commerce_store_id' => '2',
      ]));

    $variation = $this->createMock(ProductVariationInterface::class);
    $variation->method('isPublished')->willReturn(TRUE);
    $variation->method('id')->willReturn('7');
    $variation->method('getTitle')->willReturn('Ticket');
    $variation->method('getSku')->willReturn('TICKET-1');
    $variation->method('getPrice')->willReturn(new Price('10.00', 'USD'));
    $variation->expects($this->once())
      ->method('getProduct')
      ->willReturn($this->createConfiguredMock(\Drupal\commerce_product\Entity\ProductInterface::class, [
        'getStores' => [$store],
      ]));

    $result = $this->service->getTicketVariationPricing($account, $variation);

    $this->assertSame(200, $result['status']);
    $this->assertSame(7, $result['data']['variation_id']);
    $this->assertSame('10 USD', $result['data']['price']);
  }

  public function testClearCartRequiresAuthentication(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(FALSE);

    $result = $this->service->clearCart($account);

    $this->assertSame(401, $result['status']);
  }

  public function testGetMyBookedEventsReturnsUnauthorizedForAnonymous(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(FALSE);

    $result = $this->service->getMyBookedEvents($account, 'upcoming', []);

    $this->assertSame(401, $result['status']);
  }

  public function testGetUnifiedBookingsBucketDefaultsAndSegments(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);

    $this->courtBookingApi
      ->method('buildMyBookingsResponse')
      ->willReturn([
        'status' => 200,
        'data' => [
          'rows' => [],
          'pager' => ['current_page' => 0, 'total_items' => '0', 'total_pages' => 1, 'items_per_page' => 10],
        ],
      ]);

    $this->configFactory->method('get')
      ->with('event_booking.settings')
      ->willReturn($this->createConfigMock([
        'event_node_bundle' => '',
        'event_ticket_variation_field' => '',
      ]));

    $result = $this->service->getUnifiedBookings($account, []);

    $this->assertSame(200, $result['status']);
    $this->assertSame('upcoming', $result['data']['bucket']);
    $this->assertArrayHasKey('court', $result['data']['segments']);
    $this->assertArrayHasKey('event', $result['data']['segments']);
  }

  /**
   * @param array<string, mixed> $values
   */
  private function createConfigMock(array $values): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key) => $values[$key] ?? NULL);
    return $config;
  }

}

