<?php

namespace {
  if (!function_exists('user_login_finalize')) {
    function user_login_finalize($account) {
      \Drupal\Tests\login_logout\Unit\Service\UserLoginFinalizeSpy::$globalCalledWith = $account;
    }
  }
}

namespace Drupal\login_logout\Form {
  if (!function_exists('Drupal\login_logout\Form\user_login_finalize')) {
    function user_login_finalize($account) {
      \Drupal\Tests\login_logout\Unit\Service\UserLoginFinalizeSpy::$calledWith = $account;
    }
  }
}

namespace Drupal\Tests\login_logout\Unit\Service {

use Drupal\active_sessions\Service\ActiveSessionService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\login_logout\Exception\JwtPayloadException;
use Drupal\login_logout\Service\OAuthLoginService;
use Drupal\login_logout\Service\UserRegistrationAccountManager;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class UserLoginFinalizeSpy {
  public static $calledWith;
  public static $globalCalledWith;
}

/**
 * @coversDefaultClass \Drupal\login_logout\Service\UserRegistrationAccountManager
 * @group login_logout
 */
class UserRegistrationAccountManagerTest extends UnitTestCase
{
  protected $oauthLoginService;
  protected $activeSessionService;
  protected $session;
  protected $time;
  protected $messenger;
  protected $moduleHandler;
  protected $entityTypeManager;
  protected $entityStorage;
  protected $manager;

  protected function setUp(): void
  {
    parent::setUp();

    UserLoginFinalizeSpy::$calledWith = NULL;
    UserLoginFinalizeSpy::$globalCalledWith = NULL;
    $this->oauthLoginService = $this->createMock(OAuthLoginService::class);
    $this->activeSessionService = $this->createMock(ActiveSessionService::class);
    $this->session = $this->createMock(SessionInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityStorage = $this->createMock(EntityStorageInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->entityTypeManager
      ->method('getStorage')
      ->with('user')
      ->willReturn($this->entityStorage);

    $this->manager = new class(
      $this->oauthLoginService,
      $this->activeSessionService,
      $this->session,
      $this->time,
      $this->messenger,
      $this->moduleHandler,
      $this->entityTypeManager
    ) extends UserRegistrationAccountManager {
      public function callPerformLoginAfterRegistration(string $email, string $password): ?array {
        return $this->performLoginAfterRegistration($email, $password);
      }
      public function callHasRequiredTokens(?array $tokenData): bool {
        return $this->hasRequiredTokens($tokenData);
      }
      public function callHasValidJwtSubject(string $idToken): bool {
        return $this->hasValidJwtSubject($idToken);
      }
      public function callStoreTokens(array $tokenData, int $loginTime): void {
        $this->storeTokens($tokenData, $loginTime);
      }
      public function callFindClosestActiveSessionId(string $accessToken, int $loginTime): ?string {
        return $this->findClosestActiveSessionId($accessToken, $loginTime);
      }
      public function callExtractCloserSessionId(array $session, int $targetTimeMs, int $closestDiff): ?string {
        return $this->extractCloserSessionId($session, $targetTimeMs, $closestDiff);
      }
      public function callCreateDrupalUser(array $data, string $password): UserInterface {
        return $this->createDrupalUser($data, $password);
      }
      public function callFinalizeUserLogin(UserInterface $user): void {
        $this->finalizeUserLogin($user);
      }
    };
  }

  /**
   * @covers ::finalizeRegistration
   * @covers ::performLoginAfterRegistration
   * @covers ::hasRequiredTokens
   */
  public function testFinalizeRegistrationAddsErrorWhenTokensMissing(): void
  {
    $formState = $this->createMock(FormStateInterface::class);

    $this->oauthLoginService->method('getFlowId')->willReturn('flow-1');
    $this->oauthLoginService->method('authenticateUser')->willReturn(['code' => 'auth-code']);
    $this->oauthLoginService->method('exchangeCodeForToken')->willReturn(['id_token' => '']);

    $this->messenger->expects($this->once())->method('addError');
    $formState->expects($this->never())->method('setRedirect');

    $this->manager->finalizeRegistration(['mail' => 'john@example.com'], 'secret123', $formState);
  }

  /**
   * @covers ::finalizeRegistration
   * @covers ::hasValidJwtSubject
   * @covers ::storeTokens
   * @covers ::findClosestActiveSessionId
   * @covers ::createDrupalUser
   * @covers ::finalizeUserLogin
   */
  public function testFinalizeRegistrationSuccess(): void
  {
    $formState = $this->createMock(FormStateInterface::class);
    $user = $this->createMock(UserInterface::class);
    $data = [
      'first_name' => 'John',
      'last_name' => 'Doe',
      'mail' => 'john@example.com',
      'country_code' => '+91',
      'mobile' => '1234567890',
    ];

    $this->oauthLoginService->method('getFlowId')->willReturn('flow-1');
    $this->oauthLoginService->method('authenticateUser')->willReturn(['code' => 'auth-code']);
    $this->oauthLoginService->method('exchangeCodeForToken')->willReturn([
      'access_token' => 'access-token',
      'id_token' => 'id-token',
    ]);
    $this->oauthLoginService->method('decodeJwt')->with('id-token')->willReturn(['sub' => 'john@example.com']);

    $this->time->method('getRequestTime')->willReturn(123);
    $this->activeSessionService->method('fetchActiveSessions')->with('access-token')->willReturn([
      'sessions' => [
        ['id' => 'old', 'loginTime' => 122000],
        ['id' => 'best', 'loginTime' => 123050],
      ],
    ]);

    $this->session->expects($this->exactly(4))
      ->method('set')
      ->willReturnCallback(function ($key, $value) {
        static $calls = [];
        $calls[] = [$key, $value];
        $expected = [
          ['login_logout.access_token', 'access-token'],
          ['login_logout.id_token', 'id-token'],
          ['login_logout.login_time', 123],
          ['login_logout.active_session_id_token', 'best'],
        ];
        $this->assertSame($expected[count($calls) - 1], [$key, $value]);
      });

    $this->entityStorage->expects($this->once())->method('create')->willReturn($user);
    $user->expects($this->once())->method('enforceIsNew');
    $user->expects($this->once())->method('save');

    $this->moduleHandler->method('moduleExists')->with('user')->willReturn(FALSE);
    $this->messenger->expects($this->once())->method('addStatus');
    $formState->expects($this->once())->method('setRedirect')->with('<front>');

    $this->manager->finalizeRegistration($data, 'secret123', $formState);

    $this->assertSame($user, UserLoginFinalizeSpy::$calledWith);
  }

  /**
   * @covers ::__construct
   */
  public function testConstructorCanInstantiateConcreteManager(): void
  {
    $manager = new UserRegistrationAccountManager(
      $this->oauthLoginService,
      $this->activeSessionService,
      $this->session,
      $this->time,
      $this->messenger,
      $this->moduleHandler,
      $this->entityTypeManager
    );

    $this->assertInstanceOf(UserRegistrationAccountManager::class, $manager);
  }

  /**
   * @covers ::performLoginAfterRegistration
   */
  public function testPerformLoginAfterRegistrationHandlesMissingFlowAndAuthFailure(): void
  {
    $this->oauthLoginService->method('getFlowId')->willReturnOnConsecutiveCalls(NULL, 'flow-2');
    $this->oauthLoginService->method('authenticateUser')->willReturn(['message' => 'Auth failed']);

    $this->messenger->expects($this->exactly(2))->method('addError');

    $this->assertNull($this->manager->callPerformLoginAfterRegistration('john@example.com', 'secret123'));
    $this->assertNull($this->manager->callPerformLoginAfterRegistration('john@example.com', 'secret123'));
  }

  /**
   * @covers ::hasRequiredTokens
   * @covers ::extractCloserSessionId
   * @covers ::findClosestActiveSessionId
   */
  public function testTokenAndSessionHelpers(): void
  {
    $this->assertTrue($this->manager->callHasRequiredTokens([
      'access_token' => 'a',
      'id_token' => 'b',
    ]));
    $this->assertFalse($this->manager->callHasRequiredTokens([
      'access_token' => 'a',
    ]));

    $this->assertNull($this->manager->callExtractCloserSessionId([], 1000, 500));
    $this->assertNull($this->manager->callExtractCloserSessionId(['id' => 'x', 'loginTime' => 2000], 1000, 500));
    $this->assertSame('ok', $this->manager->callExtractCloserSessionId(['id' => 'ok', 'loginTime' => 1100], 1000, 500));

    $this->activeSessionService->method('fetchActiveSessions')->willReturnMap([
      ['access-2', [
        'sessions' => [
          ['id' => 'far', 'loginTime' => 1300],
          ['id' => 'near', 'loginTime' => 1010],
        ],
      ]],
      ['access-3', [
        'sessions' => [
          ['id' => 'skip-me'],
        ],
      ]],
    ]);

    $this->assertSame('near', $this->manager->callFindClosestActiveSessionId('access-2', 1));
    $this->assertNull($this->manager->callFindClosestActiveSessionId('access-3', 1));
  }

  /**
   * @covers ::hasValidJwtSubject
   * @covers ::storeTokens
   * @covers ::createDrupalUser
   * @covers ::finalizeUserLogin
   */
  public function testRemainingHelpers(): void
  {
    $user = $this->createMock(UserInterface::class);

    $this->oauthLoginService->method('decodeJwt')->willReturnOnConsecutiveCalls(['sub' => 'john@example.com'], []);
    $this->assertTrue($this->manager->callHasValidJwtSubject('token-1'));
    $this->assertFalse($this->manager->callHasValidJwtSubject('token-2'));

    $this->session->expects($this->exactly(3))
      ->method('set')
      ->willReturnCallback(function ($key, $value) {
        static $calls = [];
        $calls[] = [$key, $value];
        $expected = [
          ['login_logout.access_token', 'a'],
          ['login_logout.id_token', 'b'],
          ['login_logout.login_time', 999],
        ];
        $this->assertSame($expected[count($calls) - 1], [$key, $value]);
      });
    $this->manager->callStoreTokens(['access_token' => 'a', 'id_token' => 'b'], 999);

    $this->entityStorage->expects($this->once())->method('create')->willReturn($user);
    $user->expects($this->once())->method('enforceIsNew');
    $user->expects($this->once())->method('save');
    $this->assertSame($user, $this->manager->callCreateDrupalUser([
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'mail' => 'jane@example.com',
      'country_code' => '+1',
      'mobile' => '9999999999',
    ], 'pw'));

    $this->moduleHandler->method('moduleExists')->with('user')->willReturn(FALSE);
    $this->manager->callFinalizeUserLogin($user);
    $this->assertSame($user, UserLoginFinalizeSpy::$calledWith);
  }

  /**
   * @covers ::finalizeRegistration
   * @covers ::hasValidJwtSubject
   */
  public function testFinalizeRegistrationThrowsOnMissingJwtSubject(): void
  {
    $formState = $this->createMock(FormStateInterface::class);

    $this->oauthLoginService->method('getFlowId')->willReturn('flow-jwt');
    $this->oauthLoginService->method('authenticateUser')->willReturn(['code' => 'auth-code']);
    $this->oauthLoginService->method('exchangeCodeForToken')->willReturn([
      'access_token' => 'access-token',
      'id_token' => 'invalid-id-token',
    ]);
    $this->oauthLoginService->method('decodeJwt')->with('invalid-id-token')->willReturn([]);

    $this->expectException(JwtPayloadException::class);
    $this->expectExceptionMessage('JWT payload missing "sub" claim.');

    $this->manager->finalizeRegistration(['mail' => 'john@example.com'], 'secret123', $formState);
  }

  /**
   * @covers ::finalizeUserLogin
   */
  public function testFinalizeUserLoginUsesGlobalFunctionWhenUserModuleExists(): void
  {
    $user = $this->createMock(UserInterface::class);
    $this->moduleHandler->method('moduleExists')->with('user')->willReturn(TRUE);

    $this->manager->callFinalizeUserLogin($user);

    $this->assertSame($user, UserLoginFinalizeSpy::$globalCalledWith);
  }
}

}
