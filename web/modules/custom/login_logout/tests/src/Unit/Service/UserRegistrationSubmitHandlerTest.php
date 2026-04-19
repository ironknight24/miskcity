<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\login_logout\Service\UserRegistrationAccountManager;
use Drupal\login_logout\Service\UserRegistrationSubmitHandler;
use Drupal\login_logout\Service\UserRegistrationExternalService;
use Drupal\login_logout\Service\OtpService;
use Drupal\login_logout\Service\RegistrationAuditService;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\UserRegistrationSubmitHandler
 * @group login_logout
 */
class UserRegistrationSubmitHandlerTest extends UnitTestCase
{
  protected $messenger;
  protected $entityTypeManager;
  protected $entityStorage;
  protected $accountManager;
  protected $externalRegistrationService;
  protected $otpService;
  protected $auditService;
  protected $handler;

  protected function setUp(): void
  {
    parent::setUp();

    $this->messenger = $this->createMock(MessengerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityStorage = $this->createMock(EntityStorageInterface::class);
    $this->accountManager = $this->createMock(UserRegistrationAccountManager::class);
    $this->externalRegistrationService = $this->createMock(UserRegistrationExternalService::class);
    $this->otpService = $this->createMock(OtpService::class);
    $this->auditService = $this->createMock(RegistrationAuditService::class);

    $this->entityTypeManager->method('getStorage')->with('user')->willReturn($this->entityStorage);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->handler = new class(
      $this->messenger,
      $this->entityTypeManager,
      $this->accountManager,
      $this->externalRegistrationService,
      $this->otpService,
      $this->auditService
    ) extends UserRegistrationSubmitHandler {
      public function callHandleOtpRequest(FormStateInterface $formState): ?JsonResponse {
        return $this->handleOtpRequest($formState);
      }
      public function callHandleOtpVerification(FormStateInterface $formState): ?JsonResponse {
        return $this->handleOtpVerification($formState);
      }
      public function callHandleFinalRegistration(FormStateInterface $formState): ?JsonResponse {
        return $this->handleFinalRegistration($formState);
      }
      public function callCollectRegistrationData(FormStateInterface $formState): array {
        return $this->collectRegistrationData($formState);
      }
      public function callEmailAlreadyRegistered(string $email): bool {
        return $this->emailAlreadyRegistered($email);
      }
      public function callPasswordsMatch(string $password, string $confirmPassword, FormStateInterface $formState): bool {
        return $this->passwordsMatch($password, $confirmPassword, $formState);
      }
    };
  }

  /**
   * @covers ::handleFormSubmission
   */
  public function testHandleFormSubmissionPhases(): void
  {
    $form = [];
    
    // Phase 1
    $fs1 = $this->createMock(FormStateInterface::class);
    $fs1->method('get')->with('phase')->willReturn(1);
    $this->entityStorage->method('loadByProperties')->willReturn(['existing']);
    $this->assertNull($this->handler->handleFormSubmission($form, $fs1));

    // Phase 2
    $fs2 = $this->createMock(FormStateInterface::class);
    $fs2->method('get')->willReturnMap([['phase', 2], ['otp_code', '123456']]);
    $fs2->method('getValue')->with('otp')->willReturn('123456');
    $this->assertNull($this->handler->handleFormSubmission($form, $fs2));

    // Phase 3
    $fs3 = $this->createMock(FormStateInterface::class);
    $fs3->method('get')->willReturnMap([['phase', 3], ['user_data', $this->userData()]]);
    $fs3->method('getValue')->willReturnMap([['password', NULL, 'p'], ['confirm_password', NULL, 'p']]);
    $this->externalRegistrationService->method('registerApiUser')->willReturn(TRUE);
    $this->assertNull($this->handler->handleFormSubmission($form, $fs3));

    // Default
    $fsD = $this->createMock(FormStateInterface::class);
    $fsD->method('get')->with('phase')->willReturn(99);
    $this->assertNull($this->handler->handleFormSubmission($form, $fsD));
  }

  /**
   * @covers ::handleOtpRequest
   */
  public function testHandleOtpRequestReturnsNullOnSuccess(): void
  {
    $formState = $this->createRegistrationFormState();
    $this->entityStorage->method('loadByProperties')->willReturn([]);
    $this->otpService->method('enforceOtpRateLimit')->willReturn(NULL);
    $this->otpService->method('generateOtp')->willReturn('123456');
    $this->otpService->method('sendOtpWithLock')->willReturn(NULL);

    $this->assertNull($this->handler->callHandleOtpRequest($formState));
  }

  /**
   * @covers ::handleOtpVerification
   */
  public function testHandleOtpVerification(): void
  {
    $invalid = $this->createMock(FormStateInterface::class);
    $invalid->method('getValue')->with('otp')->willReturn('222222');
    $invalid->method('get')->with('otp_code')->willReturn('111111');
    $invalid->expects($this->once())->method('setRebuild');
    $this->messenger->expects($this->once())->method('addError');
    $this->assertNull($this->handler->callHandleOtpVerification($invalid));

    $valid = $this->createMock(FormStateInterface::class);
    $valid->method('getValue')->with('otp')->willReturn('123456');
    $valid->method('get')->with('otp_code')->willReturn('123456');
    $valid->expects($this->once())->method('set')->with('phase', 3);
    $valid->expects($this->once())->method('setRebuild');
    $this->assertNull($this->handler->callHandleOtpVerification($valid));
  }

  /**
   * @covers ::handleOtpRequest
   * @covers ::emailAlreadyRegistered
   */
  public function testHandleOtpRequestStopsWhenEmailExists(): void
  {
    $formState = $this->createRegistrationFormState();
    $this->entityStorage->method('loadByProperties')->willReturn(['existing-user']);
    $this->messenger->expects($this->once())->method('addError');

    $this->assertNull($this->handler->callHandleOtpRequest($formState));
  }

  /**
   * @covers ::handleOtpRequest
   * @covers ::processOtpRequest
   */
  public function testHandleOtpRequestReturnsResponseFromOtpService(): void
  {
    $formState = $this->createRegistrationFormState();
    $this->entityStorage->method('loadByProperties')->willReturn([]);
    $response = new JsonResponse(['status' => FALSE], 429);
    $this->otpService->method('enforceOtpRateLimit')->willReturn($response);

    $this->assertSame($response, $this->handler->callHandleOtpRequest($formState));
  }

  /**
   * @covers ::handleOtpRequest
   * @covers ::processOtpRequest
   */
  public function testHandleOtpRequestSuccess(): void
  {
    $formState = $this->createRegistrationFormState();
    $this->entityStorage->method('loadByProperties')->willReturn([]);
    $this->otpService->method('enforceOtpRateLimit')->willReturn(NULL);
    $this->otpService->method('generateOtp')->willReturn('123456');
    $this->otpService->method('sendOtpWithLock')->willReturn(NULL);

    $formState->expects($this->exactly(3))->method('set');
    $formState->expects($this->once())->method('setRebuild');

    $this->assertNull($this->handler->callHandleOtpRequest($formState));
  }

  /**
   * @covers ::handleFinalRegistration
   * @covers ::passwordsMatch
   */
  public function testHandleFinalRegistrationPasswordMismatch(): void
  {
    $mismatch = $this->createMock(FormStateInterface::class);
    $mismatch->method('getValue')->willReturnMap([
      ['password', NULL, 'secret123'],
      ['confirm_password', NULL, 'different'],
    ]);
    $mismatch->expects($this->once())->method('setRebuild');
    $this->messenger->expects($this->once())->method('addError');
    $this->assertNull($this->handler->callHandleFinalRegistration($mismatch));
  }

  /**
   * @covers ::handleFinalRegistration
   */
  public function testHandleFinalRegistrationStopsOnApiFailure(): void
  {
    $apiFailure = $this->createMock(FormStateInterface::class);
    $apiFailure->method('getValue')->willReturnMap([
      ['password', NULL, 'secret123'],
      ['confirm_password', NULL, 'secret123'],
    ]);
    $apiFailure->method('get')->with('user_data')->willReturn($this->userData());
    $this->externalRegistrationService->method('registerApiUser')->willReturn(FALSE);
    $this->assertNull($this->handler->callHandleFinalRegistration($apiFailure));
  }

  /**
   * @covers ::handleFinalRegistration
   */
  public function testHandleFinalRegistrationSuccessDelegatesToAccountManager(): void
  {
    $success = $this->createMock(FormStateInterface::class);
    $success->method('getValue')->willReturnMap([
      ['password', NULL, 'secret123'],
      ['confirm_password', NULL, 'secret123'],
    ]);
    $success->method('get')->with('user_data')->willReturn($this->userData());

    $this->externalRegistrationService->method('registerApiUser')->willReturn(TRUE);
    $this->accountManager->expects($this->once())->method('finalizeRegistration');

    $this->assertNull($this->handler->callHandleFinalRegistration($success));
  }

  /**
   * @covers ::collectRegistrationData
   */
  public function testCollectRegistrationData(): void
  {
    $formState = $this->createRegistrationFormState();
    $data = $this->handler->callCollectRegistrationData($formState);
    $this->assertSame('John', $data['first_name']);
    $this->assertSame('john@example.com', $data['mail']);
  }

  protected function createRegistrationFormState(): FormStateInterface
  {
    $formState = $this->createMock(FormStateInterface::class);
    $formState->method('getValue')->willReturnMap([
      ['first_name', NULL, 'John'],
      ['last_name', NULL, 'Doe'],
      ['mail', NULL, 'john@example.com'],
      ['country_code', NULL, '+91'],
      ['mobile', NULL, '1234567890'],
    ]);

    return $formState;
  }

  protected function userData(): array
  {
    return [
      'first_name' => 'John',
      'last_name' => 'Doe',
      'mail' => 'john@example.com',
      'country_code' => '+91',
      'mobile' => '1234567890',
    ];
  }
}
