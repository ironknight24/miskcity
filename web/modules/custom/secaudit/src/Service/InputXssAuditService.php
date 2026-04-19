<?php

declare(strict_types=1);

namespace Drupal\secaudit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Audits request input for XSS-like payloads.
 */
class InputXssAuditService
{
  protected RequestStack $requestStack;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected InputXssScanService $scanService;

  public function __construct(RequestStack $request_stack, LoggerChannelFactoryInterface $logger_factory, InputXssScanService $scanService)
  {
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
    $this->scanService = $scanService;
  }

  public function detectIE1(): array
  {
    $request = $this->requestStack->getCurrentRequest();
    if (!$this->scanService->shouldScanRequest($request)) {
      return [];
    }

    $findings = $this->scanService->scanInputs($this->scanService->collectInputs($request));

    if (!empty($findings)) {
      $this->logIE1($request, $findings);
    }

    return $findings;
  }

  protected function logIE1($request, array $findings): void
  {
    $this->loggerFactory->get('secaudit')->warning(
      'IE1: Cross Site Scripting Attempt detected. IP: @ip, Path: @path, Findings Count: @count',
      [
        '@ip' => $request->headers->all()['x-real-ip'][0] ?? $request->getClientIp(),
        '@path' => $request->getPathInfo(),
        '@count' => count($findings),
        '@details' => $findings,
      ]
    );
  }
}
