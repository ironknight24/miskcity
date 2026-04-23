<?php

namespace Drupal\login_logout\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\login_logout\Exception\OAuthLoginException;
use Drupal\login_logout\Service\OAuthLoginService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * REST endpoints for login/token acquisition.
 */
final class AuthRestController extends ControllerBase {

  public function __construct(
    protected OAuthLoginService $oauthLoginService,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('login_logout.oauth_login_service'),
      $container->get('logger.channel.login_logout'),
    );
  }

  /**
   * Issues OAuth access token for API clients.
   */
  public function login(Request $request): JsonResponse {
    $raw = $request->getContent();
    $data = $raw !== '' ? json_decode($raw, TRUE) : [];
    if (!is_array($data)) {
      throw new BadRequestHttpException('Invalid JSON body.');
    }

    $email = trim((string) ($data['email'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    if ($email === '' || $password === '') {
      return new JsonResponse([
        'message' => 'Missing required fields: email, password.',
      ], 400);
    }

    try {
      $token_data = $this->oauthLoginService->performOAuthLogin($email, $password);
      if (!is_array($token_data) || empty($token_data['access_token'])) {
        return new JsonResponse([
          'message' => 'Authentication failed.',
        ], 401);
      }

      $response = [
        'access_token' => (string) $token_data['access_token'],
        'token_type' => (string) ($token_data['token_type'] ?? 'Bearer'),
        'expires_in' => (int) ($token_data['expires_in'] ?? 3600),
      ];
      if (!empty($token_data['refresh_token'])) {
        $response['refresh_token'] = (string) $token_data['refresh_token'];
      }
      if (!empty($token_data['id_token'])) {
        $response['id_token'] = (string) $token_data['id_token'];
      }

      return new JsonResponse($response, 200);
    }
    catch (OAuthLoginException $e) {
      $this->logger->notice('REST auth login rejected for @email: @msg', [
        '@email' => $email,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'message' => 'Invalid credentials.',
      ], 401);
    }
    catch (\Throwable $e) {
      $this->logger->error('REST auth login failed for @email: @msg', [
        '@email' => $email,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'message' => 'Authentication service unavailable.',
      ], 503);
    }
  }

}
