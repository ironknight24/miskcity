<?php

declare(strict_types=1);

namespace Drupal\secaudit\Service;

/**
 * Thin facade that coordinates the specialized audit services.
 */
class AuditService
{
  protected ForceBrowsingAuditService $forceBrowsingAuditService;
  protected HttpMethodAuditService $httpMethodAuditService;
  protected CookieAuditService $cookieAuditService;
  protected InputEncodingAuditService $inputEncodingAuditService;
  protected InputXssAuditService $inputXssAuditService;

  public function __construct(
    ForceBrowsingAuditService $forceBrowsingAuditService,
    HttpMethodAuditService $httpMethodAuditService,
    CookieAuditService $cookieAuditService,
    InputEncodingAuditService $inputEncodingAuditService,
    InputXssAuditService $inputXssAuditService
  ) {
    $this->forceBrowsingAuditService = $forceBrowsingAuditService;
    $this->httpMethodAuditService = $httpMethodAuditService;
    $this->cookieAuditService = $cookieAuditService;
    $this->inputEncodingAuditService = $inputEncodingAuditService;
    $this->inputXssAuditService = $inputXssAuditService;
  }

  public function detectForceBrowsing(): void
  {
    $this->forceBrowsingAuditService->detectForceBrowsing();
  }

  public function detectUnexpectedHttpMethod(): void
  {
    $this->httpMethodAuditService->detectUnexpectedHttpMethod();
  }

  public function detectUnsupportedHttpMethods(): void
  {
    $this->httpMethodAuditService->detectUnsupportedHttpMethods();
  }

  public function detectCookieTampering(): void
  {
    $this->cookieAuditService->detectCookieTampering();
  }

  public function detectIE1(): array
  {
    return $this->inputXssAuditService->detectIE1();
  }

  public function detectEE1(): void
  {
    $this->inputEncodingAuditService->detectEE1();
  }

  public function detectEE2(): void
  {
    $this->inputEncodingAuditService->detectEE2();
  }
}
