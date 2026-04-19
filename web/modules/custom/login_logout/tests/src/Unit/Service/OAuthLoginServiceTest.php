<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\login_logout\Exception\OAuthLoginException;
use Drupal\login_logout\Service\OAuthLoginService;
use Drupal\login_logout\Service\OAuthHelperService;
use Drupal\global_module\Service\GlobalVariablesService;
use Drupal\global_module\Service\VaultConfigService;
use Drupal\global_module\Service\ApimanTokenService;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Tests\UnitTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\OAuthLoginService
 * @group login_logout
 */
class OAuthLoginServiceTest extends UnitTestCase {

  protected $httpClient;
  protected $logger;
  protected $requestStack;
  protected $globalVariablesService;
  protected $vaultConfigService;
  protected $apimanTokenService;
  protected $oauthHelperService;
  protected $service;

  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->createMock(ClientInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->globalVariablesService = $this->createMock(GlobalVariablesService::class);
    $this->vaultConfigService = $this->createMock(VaultConfigService::class);
    $this->apimanTokenService = $this->createMock(ApimanTokenService::class);
    $this->oauthHelperService = $this->createMock(OAuthHelperService::class);

    $this->service = new OAuthLoginService(
      $this->httpClient,
      $this->logger,
      $this->requestStack,
      $this->globalVariablesService,
      $this->vaultConfigService,
      $this->apimanTokenService,
      $this->oauthHelperService
    );
  }

  /**
   * @covers ::__construct
   */
  public function testConstructor() {
    $this->assertInstanceOf(OAuthLoginService::class, $this->service);
  }

  /**
   * @covers ::getFlowId
   */
  public function testGetFlowIdSuccess() {
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.com']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['flowId' => 'flow123']));
    $response->method('getBody')->willReturn($body);

    $this->httpClient->method('request')->willReturn($response);

    $this->assertEquals('flow123', $this->service->getFlowId());
  }

  /**
   * @covers ::getFlowId
   */
  public function testGetFlowIdFailure() {
    $this->vaultConfigService->method('getGlobalVariables')->willThrowException(new \Exception('Vault error'));
    $this->logger->expects($this->once())->method('error');
    $this->assertNull($this->service->getFlowId());
  }

  /**
   * @covers ::format_user_agent
   */
  public function testFormatUserAgent() {
    $this->oauthHelperService->method('detectFromRules')->willReturnMap([
      ['agent', [
        'Microsoft Edge' => ['Edg'],
        'Chrome'         => ['Chrome', '!Chromium'],
        'Firefox'        => ['Firefox'],
        'Safari'         => ['Safari', '!Chrome'],
        'Opera'          => ['Opera', 'OPR'],
      ], 'Unknown Browser', 'Chrome'],
      ['agent', [
        'Desktop (Windows)' => ['Windows'],
        'Desktop (Mac)'     => ['Macintosh', 'Mac OS X'],
        'Mobile (iPhone)'   => ['iPhone'],
        'Tablet (iPad)'     => ['iPad'],
        'Mobile (Android)'  => ['Android', 'Mobile'],
        'Tablet (Android)'  => ['Android'],
        'Linux'             => ['Linux'],
      ], 'Unknown Device/OS', 'Desktop (Mac)'],
    ]);

    $result = $this->service->format_user_agent('agent');
    $this->assertEquals('Chrome, Desktop (Mac)', $result);
  }

  /**
   * @covers ::format_user_agent
   */
  public function testFormatUserAgentUnknown() {
    $this->oauthHelperService->method('detectFromRules')->willReturnOnConsecutiveCalls(
      'Unknown Browser',
      'Unknown Device/OS'
    );

    $result = $this->service->format_user_agent('unknown');
    $this->assertEquals('unknown', $result);
  }

  /**
   * @covers ::authenticateUser
   * @covers ::getIdamConfig
   */
  public function testAuthenticateUserSuccess() {
    $request = new Request();
    $request->headers->set('User-Agent', 'agent');
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.com']]
    ]);

    $this->oauthHelperService->method('prepareAuthPayload')->willReturn([]);
    $this->oauthHelperService->method('sendAuthenticationRequest')->willReturn($this->createMock(ResponseInterface::class));
    $this->oauthHelperService->method('parseResponse')->willReturn(['status' => 'ok']);
    $this->oauthHelperService->method('isAuthSuccess')->willReturn(TRUE);
    $this->oauthHelperService->method('handleAuthSuccess')->willReturn(['success' => TRUE, 'code' => 'abc']);

    $result = $this->service->authenticateUser('flow', 'user', 'pass');
    $this->assertTrue($result['success']);
  }

  /**
   * @covers ::authenticateUser
   */
  public function testAuthenticateUserSessionLimit() {
    $request = new Request();
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->vaultConfigService->method('getGlobalVariables')->willReturn(['applicationConfig' => ['config' => ['idamconfig' => 'i']]]);

    $this->oauthHelperService->method('isAuthSuccess')->willReturn(FALSE);
    $this->oauthHelperService->method('isActiveSessionLimitReached')->willReturn(TRUE);
    $this->oauthHelperService->method('handleSessionLimit')->willReturn(['success' => FALSE, 'message' => 'limit']);

    $result = $this->service->authenticateUser('f', 'u', 'p');
    $this->assertEquals('limit', $result['message']);
  }

  /**
   * @covers ::authenticateUser
   */
  public function testAuthenticateUserError() {
    $request = new Request();
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->vaultConfigService->method('getGlobalVariables')->willReturn(['applicationConfig' => ['config' => ['idamconfig' => 'i']]]);

    $this->oauthHelperService->method('isAuthSuccess')->willReturn(FALSE);
    $this->oauthHelperService->method('isActiveSessionLimitReached')->willReturn(FALSE);
    $this->oauthHelperService->method('handleErrorResponse')->willReturn(['success' => FALSE, 'message' => 'fail']);

    $result = $this->service->authenticateUser('f', 'u', 'p');
    $this->assertEquals('fail', $result['message']);
  }

  /**
   * @covers ::authenticateUser
   */
  public function testAuthenticateUserException() {
    $this->requestStack->method('getCurrentRequest')->willThrowException(new \Exception('Err'));
    $this->oauthHelperService->expects($this->once())->method('logError');
    $this->oauthHelperService->method('generateErrorResponse')->willReturn(['success' => FALSE]);

    $result = $this->service->authenticateUser('f', 'u', 'p');
    $this->assertFalse($result['success']);
  }

  /**
   * @covers ::decodeJwt
   */
  public function testDecodeJwt() {
    $this->oauthHelperService->method('isValidJwtFormat')->willReturn(TRUE);
    $this->oauthHelperService->method('extractPayloadFromJwt')->willReturn('p');
    $this->oauthHelperService->method('decodeBase64Url')->willReturn(['sub' => '123']);

    $this->assertEquals(['sub' => '123'], $this->service->decodeJwt('a.b.c'));
  }

  /**
   * @covers ::decodeJwt
   */
  public function testDecodeJwtInvalid() {
    $this->oauthHelperService->method('isValidJwtFormat')->willReturn(FALSE);
    $this->assertNull($this->service->decodeJwt('invalid'));
  }

  /**
   * @covers ::exchangeCodeForToken
   * @covers ::sendTokenRequest
   */
  public function testExchangeCodeForToken() {
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.com']]
    ]);
    $this->httpClient->method('request')->willReturn($this->createMock(ResponseInterface::class));
    $this->oauthHelperService->method('parseResponse')->willReturn(['access_token' => 't']);

    $result = $this->service->exchangeCodeForToken('code');
    $this->assertEquals('t', $result['access_token']);
  }

  /**
   * @covers ::exchangeCodeForToken
   */
  public function testExchangeCodeForTokenException() {
    $this->vaultConfigService->method('getGlobalVariables')->willThrowException(new \Exception('E'));
    $this->oauthHelperService->expects($this->once())->method('logError');
    $this->assertNull($this->service->exchangeCodeForToken('code'));
  }

  /**
   * @covers ::checkEmailExists
   */
  public function testCheckEmailExists() {
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['data' => 'exists']));
    $response->method('getBody')->willReturn($body);
    $this->httpClient->method('request')->willReturn($response);

    $this->assertTrue($this->service->checkEmailExists('e', 't', 'u', 'v'));
  }

  /**
   * @covers ::checkEmailExists
   */
  public function testCheckEmailExistsException() {
    $this->httpClient->method('request')->willThrowException(new \Exception('E'));
    $this->logger->expects($this->once())->method('error');
    $this->assertFalse($this->service->checkEmailExists('e', 't', 'u', 'v'));
  }

  /**
   * @covers ::logout
   */
  public function testLogoutSuccess() {
    $request = new Request();
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'idam.com']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $response->method('getBody')->willReturn($this->createMock(StreamInterface::class));
    $this->httpClient->method('request')->willReturn($response);

    $result = $this->service->logout('hint');
    $this->assertEquals(200, $result['status']);
  }

  /**
   * @covers ::logout
   */
  public function testLogoutException() {
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());
    $this->vaultConfigService->method('getGlobalVariables')->willThrowException(new \Exception('Err'));
    $this->logger->expects($this->once())->method('error');

    $result = $this->service->logout('hint');
    $this->assertEquals(500, $result['status']);
  }

  /**
   * @covers ::performOAuthLogin
   */
  public function testPerformOAuthLoginSuccess() {
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'applicationConfig' => ['config' => ['idamconfig' => 'i']]
    ]);
    
    $request = new Request();
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    // Mock getFlowId and token exchange calls to httpClient
    $resp1 = $this->createMock(ResponseInterface::class);
    $body1 = $this->createMock(StreamInterface::class);
    $body1->method('getContents')->willReturn(json_encode(['flowId' => 'f123']));
    $resp1->method('getBody')->willReturn($body1);
    
    $this->httpClient->method('request')->willReturn($resp1);

    $this->oauthHelperService->method('prepareAuthPayload')->willReturn([]);
    $this->oauthHelperService->method('sendAuthenticationRequest')->willReturn($this->createMock(ResponseInterface::class));
    $this->oauthHelperService->method('isAuthSuccess')->willReturn(TRUE);
    $this->oauthHelperService->method('handleAuthSuccess')->willReturn(['success' => TRUE, 'code' => 'c123']);

    // parseResponse is called 2 times in this flow: 1. authenticateUser, 2. exchangeCodeForToken
    // (getFlowId uses json_decode directly)
    $this->oauthHelperService->expects($this->exactly(2))->method('parseResponse')
      ->willReturnOnConsecutiveCalls(
        ['authData' => ['code' => 'c123']],
        ['access_token' => 'at', 'id_token' => 'it']
      );

    $result = $this->service->performOAuthLogin('e', 'p');
    $this->assertEquals('at', $result['access_token']);
    $this->assertEquals('it', $result['id_token']);
  }

  /**
   * @covers ::performOAuthLogin
   */
  public function testPerformOAuthLoginNoFlowId() {
    $this->vaultConfigService->method('getGlobalVariables')->willReturn(['applicationConfig' => ['config' => ['idamconfig' => 'i']]]);
    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode([]));
    $response->method('getBody')->willReturn($body);
    $this->httpClient->method('request')->willReturn($response);

    $this->expectException(OAuthLoginException::class);
    $this->expectExceptionMessage('Flow ID not received');
    $this->service->performOAuthLogin('e', 'p');
  }

  /**
   * @covers ::performOAuthLogin
   */
  public function testPerformOAuthLoginAuthFail() {
    $this->vaultConfigService->method('getGlobalVariables')->willReturn(['applicationConfig' => ['config' => ['idamconfig' => 'i']]]);
    
    // getFlowId
    $resp1 = $this->createMock(ResponseInterface::class);
    $body1 = $this->createMock(StreamInterface::class);
    $body1->method('getContents')->willReturn(json_encode(['flowId' => 'f']));
    $resp1->method('getBody')->willReturn($body1);
    $this->httpClient->method('request')->willReturn($resp1);

    // authenticateUser
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());
    $this->oauthHelperService->method('handleErrorResponse')->willReturn(['success' => FALSE, 'message' => 'Auth fail']);

    $this->expectException(OAuthLoginException::class);
    $this->expectExceptionMessage('Auth fail');
    $this->service->performOAuthLogin('e', 'p');
  }

  /**
   * @covers ::performOAuthLogin
   */
  public function testPerformOAuthLoginTokenFail() {
    $this->vaultConfigService->method('getGlobalVariables')->willReturn(['applicationConfig' => ['config' => ['idamconfig' => 'i']]]);
    
    // getFlowId
    $resp1 = $this->createMock(ResponseInterface::class);
    $body1 = $this->createMock(StreamInterface::class);
    $body1->method('getContents')->willReturn(json_encode(['flowId' => 'f']));
    $resp1->method('getBody')->willReturn($body1);
    $this->httpClient->method('request')->willReturn($resp1);

    // authenticateUser
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());
    $this->oauthHelperService->method('isAuthSuccess')->willReturn(TRUE);
    $this->oauthHelperService->method('handleAuthSuccess')->willReturn(['success' => TRUE, 'code' => 'c']);
    
    // exchangeCodeForToken
    $this->oauthHelperService->expects($this->exactly(2))->method('parseResponse')
      ->willReturnOnConsecutiveCalls(['authData' => ['code' => 'c']], ['access_token' => 'at']);

    $this->expectException(OAuthLoginException::class);
    $this->expectExceptionMessage('Failed to receive access or ID token');
    $this->service->performOAuthLogin('e', 'p');
  }

  /**
   * @covers ::extractEmailFromJwt
   */
  public function testExtractEmailFromJwt() {
    $payload = base64_encode(json_encode(['sub' => 'user@test.com']));
    $jwt = "header.$payload.signature";
    $this->assertEquals('user@test.com', $this->service->extractEmailFromJwt($jwt));
  }

  /**
   * @covers ::extractEmailFromJwt
   */
  public function testExtractEmailFromJwtInvalid() {
    $this->assertNull($this->service->extractEmailFromJwt('invalid'));
  }

  /**
   * @covers ::validateEmail
   */
  public function testValidateEmail() {
    $this->apimanTokenService->method('getApimanAccessToken')->willReturn('t');
    $this->vaultConfigService->method('getGlobalVariables')->willReturn([
      'apiManConfig' => ['config' => ['apiUrl' => 'u', 'apiVersion' => 'v']]
    ]);

    $response = $this->createMock(ResponseInterface::class);
    $body = $this->createMock(StreamInterface::class);
    $body->method('getContents')->willReturn(json_encode(['data' => 'ok']));
    $response->method('getBody')->willReturn($body);
    $this->httpClient->method('request')->willReturn($response);

    $this->assertTrue($this->service->validateEmail('e'));
  }
}
