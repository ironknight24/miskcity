<?php

declare(strict_types=1);

namespace Drupal\secaudit\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Audits encoding-related request anomalies.
 */
class InputEncodingAuditService
{
  protected RequestStack $requestStack;
  protected LoggerChannelFactoryInterface $loggerFactory;
  protected EncodingDetectorService $detector;

  public function __construct(
    RequestStack $request_stack,
    LoggerChannelFactoryInterface $logger_factory,
    EncodingDetectorService $detector
  ) {
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
    $this->detector = $detector;
  }

  public function detectEE1(): void
  {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }

    foreach ($this->getScalarInputs($request) as $value) {
      $reason = $this->getEE1Reason($value);
      if ($reason !== NULL) {
        $this->logAnomaly($request, 'EE1', 'Double Encoded Characters detected.', $value, $reason);
        $request->attributes->set('_secaudit_ee1_detected', TRUE);
        return;
      }
    }
  }

  protected function getEE1Reason(string $value): ?string
  {
    if ($this->detector->hasDoubleEncoding($value)) {
      return 'double_url_encoding';
    }
    if ($this->detector->hasHtmlDoubleEncoding($value)) {
      return 'double_html_encoding';
    }
    return NULL;
  }

  public function detectEE2(): void
  {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request || $request->attributes->get('_secaudit_ee2_detected')) {
      return;
    }

    foreach ($this->getScalarInputs($request) as $value) {
      $reason = $this->detector->detectUnexpectedEncodingReason($value);
      if ($reason !== NULL) {
        $this->logAnomaly($request, 'EE2', 'Unexpected encoding used.', $value, $reason);
        $request->attributes->set('_secaudit_ee2_detected', TRUE);
        break;
      }
    }
  }

  protected function getScalarInputs(Request $request): \Generator
  {
    $inputs = array_merge(
      $request->query->all(),
      $request->request->all(),
      $request->cookies->all()
    );

    foreach ($inputs as $value) {
      if (is_scalar($value)) {
        yield (string) $value;
      }
    }
  }

  protected function logAnomaly(Request $request, string $code, string $message, string $value, string $reason): void
  {
    $headers = $request->headers->all();
    $this->loggerFactory->get('secaudit')->warning(
      $code . ': ' . $message . ' IP: @ip, Path: @path, Reason: @reason, Sample: @sample',
      [
        '@ip' => $headers['x-real-ip'][0] ?? $request->getClientIp(),
        '@path' => $request->getPathInfo(),
        '@reason' => $reason,
        '@sample' => substr($value, 0, 200),
      ]
    );
  }
}
