<?php

namespace Drupal\Tests\active_sessions\Unit\Controller;

use Drupal\active_sessions\Controller\ActiveSessionController;
use Drupal\active_sessions\Service\ActiveSessionPresenterService;
use Drupal\login_logout\Service\OAuthLoginService;
use Drupal\active_sessions\Service\ActiveSessionService;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for ActiveSessionController.
 *
 * @group active_sessions
 */
class ActiveSessionControllerTest extends UnitTestCase
{
    /** @var OAuthLoginService|MockObject */
    protected $mockOAuthLoginService;

    /** @var RequestStack|MockObject */
    protected $mockRequestStack;

    /** @var ActiveSessionService|MockObject */
    protected $mockSessionService;

    /** @var DateFormatterInterface|MockObject */
    protected $mockDateFormatter;

    /** @var ActiveSessionPresenterService */
    protected $sessionPresenter;

    /** @var SessionInterface|MockObject */
    protected $mockSession;

    /** @var MessengerInterface|MockObject */
    protected $mockMessenger;

    /** @var ActiveSessionController */
    protected $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
        $this->mockOAuthLoginService = $this->createMock(OAuthLoginService::class);
        $this->mockRequestStack = $this->createMock(RequestStack::class);
        $this->mockSessionService = $this->createMock(ActiveSessionService::class);
        $this->mockDateFormatter = $this->createMock(DateFormatterInterface::class);
        $this->mockSession = $this->createMock(SessionInterface::class);
        $this->mockMessenger = $this->createMock(MessengerInterface::class);
        $this->sessionPresenter = new ActiveSessionPresenterService($this->mockDateFormatter);

        // Mock Drupal container services
        $container = new ContainerBuilder();
        $container->set('session', $this->mockSession);
        $container->set('messenger', $this->mockMessenger);
        $container->set('string_translation', $this->createMock(TranslationInterface::class));
        $container->set('login_logout.oauth_login_service', $this->mockOAuthLoginService);
        $container->set('request_stack', $this->mockRequestStack);
        $container->set('active_sessions.session_service', $this->mockSessionService);
        $container->set('date.formatter', $this->mockDateFormatter);
        $container->set('active_sessions.presenter_service', $this->sessionPresenter);

        \Drupal::setContainer($container);

        // Create controller
        $this->controller = new ActiveSessionController(
            $this->mockOAuthLoginService,
            $this->mockRequestStack,
            $this->mockSessionService,
            $this->mockDateFormatter,
            $this->sessionPresenter
        );
    }

    /**
     * Test the create method.
     */
    public function testCreate()
    {
        $container = \Drupal::getContainer();
        $controller = ActiveSessionController::create($container);
        $this->assertInstanceOf(ActiveSessionController::class, $controller);
    }

    /**
     * Test activeSession with complete data.
     */
    public function testActiveSession()
    {
        $this->mockSession->method('get')
            ->willReturnMap([
                ['login_logout.access_token', null, 'dummy_access_token'],
                ['login_logout.login_time', null, 1234567], // 1234567000 ms
            ]);

        $this->mockSessionService->method('fetchActiveSessions')
            ->with('dummy_access_token')
            ->willReturn([
                'sessions' => [
                    // Closest to 1234567000
                    ['id' => '1', 'loginTime' => 1234567000, 'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'],
                    // Other session
                    ['id' => '2', 'loginTime' => 1234568000, 'userAgent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0.3 Mobile/15E148 Safari/604.1'],
                    // Edge case session without loginTime (should be skipped in closest search)
                    ['id' => '3', 'userAgent' => 'Unknown'],
                ],
            ]);

        $this->mockDateFormatter->method('format')
            ->willReturn('01-01-2023, 12:00:00');

        $response = $this->controller->activeSession();

        $this->assertArrayHasKey('#title', $response);
        $this->assertArrayHasKey('#currentUserSessions', $response);
        $this->assertArrayHasKey('#otherUserSessions', $response);
        // In UnitTestCase, $this->t() might return an empty string or the key itself depending on setup.
        // We'll just assert it's not null.
        $this->assertNotNull($response['#title']);
        
        $this->assertCount(1, $response['#currentUserSessions']);
        $this->assertCount(2, $response['#otherUserSessions']);
        
        // Check formatting for current session (Chrome on Windows)
        $this->assertEquals('Chrome, Desktop (Windows)', $response['#currentUserSessions'][0]['userAgentFormatted']);
        
        // Check formatting for other session (Safari on iPhone)
        $this->assertEquals('Safari, Mobile (iPhone)', $response['#otherUserSessions'][0]['userAgentFormatted']);
        
        // Check unknown browser fallback
        $this->assertEquals('Unknown', $response['#otherUserSessions'][1]['userAgentFormatted']);
    }

    /**
     * Test activeSession with no sessions from API.
     */
    public function testActiveSessionEmpty()
    {
        $this->mockSession->method('get')->willReturnMap([
            ['login_logout.access_token', NULL, 'some_token'],
            ['login_logout.login_time', NULL, NULL],
        ]);
        $this->mockSessionService->method('fetchActiveSessions')->willReturn(NULL);

        $response = $this->controller->activeSession();
        $this->assertEmpty($response['#currentUserSessions']);
        $this->assertEmpty($response['#otherUserSessions']);
    }

    /**
     * Test endSession with valid input.
     */
    public function testEndSessionValid()
    {
        $this->mockSession->method('get')
            ->willReturnMap([
                ['login_logout.access_token', NULL, 'dummy_token'],
                ['login_logout.active_session_id_token', NULL, 'my_id'],
            ]);

        // Case 1: Terminating another session
        $response = $this->controller->endSession('other_id--token');
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals(ActiveSessionController::MY_ACCOUNT_PATH, $response->getTargetUrl());

        // Case 2: Terminating my own session
        $response = $this->controller->endSession('my_id--token');
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/logout', $response->getTargetUrl());
    }

    /**
     * Test endSession with missing access token.
     */
    public function testEndSessionMissingToken()
    {
        $this->mockSession->method('get')
            ->willReturnMap([
                ['login_logout.access_token', NULL, NULL],
                ['login_logout.active_session_id_token', NULL, 'some_id'],
            ]);
        $this->mockMessenger->expects($this->once())->method('addError');

        $response = $this->controller->endSession('id--token');
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals(ActiveSessionController::MY_ACCOUNT_PATH, $response->getTargetUrl());
    }

    /**
     * Test endSession exception handling.
     */
    public function testEndSessionException()
    {
        $this->mockSession->method('get')->willReturn('token');
        $this->mockSessionService->method('terminateSession')->willThrowException(new \Exception('API Error'));
        $this->mockMessenger->expects($this->once())->method('addError');

        $response = $this->controller->endSession('id--token');
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * Test endAllSessions with valid token.
     */
    public function testEndAllSessionsValid()
    {
        $this->mockSession->method('get')->with('login_logout.access_token')->willReturn('token');
        
        $response = $this->controller->endAllSessions();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/logout', $response->getTargetUrl());
    }

    /**
     * Test endAllSessions missing token.
     */
    public function testEndAllSessionsMissingToken()
    {
        $this->mockSession->method('get')->with('login_logout.access_token')->willReturn(NULL);
        $this->mockMessenger->expects($this->once())->method('addError');

        $response = $this->controller->endAllSessions();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals(ActiveSessionController::MY_ACCOUNT_PATH, $response->getTargetUrl());
    }

    /**
     * Test endAllSessions exception.
     */
    public function testEndAllSessionsException()
    {
        $this->mockSession->method('get')->with('login_logout.access_token')->willReturn('token');
        $this->mockSessionService->method('terminateAllOtherSessions')->willThrowException(new \Exception('Error'));
        $this->mockMessenger->expects($this->once())->method('addError');

        $response = $this->controller->endAllSessions();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals(ActiveSessionController::MY_ACCOUNT_PATH, $response->getTargetUrl());
    }
}
