<?php

namespace Drupal\Tests\active_sessions\Unit\Service;

use Drupal\active_sessions\Service\ActiveSessionService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\HeaderBag;
use Psr\Log\LoggerInterface;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @coversDefaultClass \Drupal\active_sessions\Service\ActiveSessionService
 * @group active_sessions
 */
class ActiveSessionServiceTest extends UnitTestCase
{
    /** @var ClientInterface|MockObject */
    protected $httpClient;

    /** @var RequestStack|MockObject */
    protected $requestStack;

    /** @var LoggerInterface|MockObject */
    protected $logger;

    /** @var GlobalVariablesService|MockObject */
    protected $globalVariablesService;

    /** @var VaultConfigService|MockObject */
    protected $vaultConfigService;

    /** @var ActiveSessionService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->globalVariablesService = $this->createMock(GlobalVariablesService::class);
        $this->vaultConfigService = $this->createMock(VaultConfigService::class);

        $this->service = new ActiveSessionService(
            $this->httpClient,
            $this->requestStack,
            $this->logger,
            $this->globalVariablesService,
            $this->vaultConfigService
        );

        // Mock VaultConfigService default behavior
        $this->vaultConfigService->method('getGlobalVariables')
            ->willReturn([
                'applicationConfig' => [
                    'config' => [
                        'idamconfig' => 'idam.example.com'
                    ]
                ]
            ]);
    }

    /**
     * @covers ::fetchActiveSessions
     */
    public function testFetchActiveSessionsSuccess()
    {
        $accessToken = 'test_token';
        
        // Mock Request and RequestStack
        $request = $this->createMock(Request::class);
        $headers = $this->createMock(HeaderBag::class);
        $request->headers = $headers;
        $headers->method('get')->with('cookie')->willReturn('session_cookie=123');
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        // Mock Response
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $stream->method('getContents')->willReturn(json_encode(['sessions' => [['id' => '1']]]));

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://idam.example.com/api/users/v1/me/sessions', [
                'headers' => [
                    'Accept' => '*/*',
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Cookie' => 'session_cookie=123',
                ],
                'verify' => FALSE,
            ])
            ->willReturn($response);

        $result = $this->service->fetchActiveSessions($accessToken);
        $this->assertEquals(['sessions' => [['id' => '1']]], $result);
    }

    /**
     * @covers ::fetchActiveSessions
     */
    public function testFetchActiveSessionsFailure()
    {
        $request = $this->createMock(Request::class);
        $headers = $this->createMock(HeaderBag::class);
        $request->headers = $headers;
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        
        $this->httpClient->method('request')->willThrowException(new \Exception('Network Error'));
        $this->logger->expects($this->once())->method('error');

        $result = $this->service->fetchActiveSessions('token');
        $this->assertNull($result);
    }

    /**
     * @covers ::terminateSession
     */
    public function testTerminateSessionSuccess()
    {
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('DELETE', $this->stringContains('/api/users/v1/me/sessions/session123'))
            ->willReturn($this->createMock(ResponseInterface::class));

        $result = $this->service->terminateSession('session123', 'token');
        $this->assertTrue($result);
    }

    /**
     * @covers ::terminateSession
     */
    public function testTerminateSessionFailure()
    {
        $requestException = $this->createMock(RequestException::class);
        $this->httpClient->method('request')->willThrowException($requestException);
        $this->logger->expects($this->once())->method('error');

        $result = $this->service->terminateSession('session123', 'token');
        $this->assertFalse($result);
    }

    /**
     * @covers ::terminateAllOtherSessions
     */
    public function testTerminateAllOtherSessionsSuccess()
    {
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('DELETE', 'https://idam.example.com/api/users/v1/me/sessions')
            ->willReturn($this->createMock(ResponseInterface::class));

        $result = $this->service->terminateAllOtherSessions('token');
        $this->assertTrue($result);
    }

    /**
     * @covers ::terminateAllOtherSessions
     */
    public function testTerminateAllOtherSessionsFailure()
    {
        $requestException = $this->createMock(RequestException::class);
        $this->httpClient->method('request')->willThrowException($requestException);
        $this->logger->expects($this->once())->method('error');

        $result = $this->service->terminateAllOtherSessions('token');
        $this->assertFalse($result);
    }
}
