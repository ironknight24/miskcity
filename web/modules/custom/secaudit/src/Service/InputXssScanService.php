<?php

declare(strict_types=1);

namespace Drupal\secaudit\Service;

use Symfony\Component\HttpFoundation\Request;

class InputXssScanService
{
  protected int $maxScanLength = 4096;
  protected int $maxFindings = 10;
  protected InputXssMatcherService $matcherService;

  /**
   * @var string[]
   */
  protected array $ignorePathPrefixes = [
    '/admin',
    '/core',
    '/profiles',
    '/modules',
    '/sites/default/files',
    '/sites/simpletest',
    '/favicon.ico',
    '/robots.txt',
    '/_profiler',
    '/visitors/_track',
  ];

  public function __construct(?InputXssMatcherService $matcherService = null)
  {
    $this->matcherService = $matcherService ?? new InputXssMatcherService();
  }

  public function shouldScanRequest(?Request $request): bool
  {
    if (!$request || $request->attributes->get('_secaudit_ee1_detected')) {
      return false;
    }

    $pathInfo = $request->getPathInfo() ?? '/';
    foreach ($this->ignorePathPrefixes as $prefix) {
      if (str_starts_with($pathInfo, $prefix)) {
        return false;
      }
    }

    return true;
  }

  public function collectInputs(Request $request): array
  {
    $inputs = [
      'query' => $request->query->all(),
      'request' => $request->request->all(),
      'cookies' => $request->cookies->all(),
    ];

    $contentType = (string) $request->headers->get('Content-Type', '');
    if (stripos($contentType, 'application/json') !== false) {
      $decoded = json_decode((string) $request->getContent(), true);
      if (is_array($decoded)) {
        $inputs['json_body'] = $decoded;
      }
    }

    return $inputs;
  }

  public function scanInputs(array $inputs): array
  {
    $findings = [];

    foreach ($inputs as $type => $values) {
      $this->scanValues($type, $values, $findings);
      if (count($findings) >= $this->maxFindings) {
        break;
      }
    }

    return $findings;
  }

  protected function scanValues(string $type, $values, array &$findings): void
  {
    $queue = [$values];

    while ($queue !== [] && count($findings) < $this->maxFindings) {
      $value = array_shift($queue);
      if (!$this->canScanValue($value)) {
        continue;
      }

      if (is_array($value)) {
        $this->appendArrayValues($queue, $value);
        continue;
      }

      $normalizedValue = (string) $value;
      $pattern = $this->findMatchingPattern($normalizedValue);
      if ($this->isScannableString($normalizedValue) && $pattern !== null) {
        $findings[] = [
          'type' => $type,
          'value' => $normalizedValue,
          'pattern' => $pattern,
        ];
      }
    }
  }

  protected function canScanValue($values): bool
  {
    return is_array($values) || is_scalar($values);
  }

  protected function appendArrayValues(array &$queue, array $values): void
  {
    foreach ($values as $nestedValue) {
      $queue[] = $nestedValue;
    }
  }

  protected function isScannableString(string $value): bool
  {
    return strlen($value) <= $this->maxScanLength;
  }

  protected function findMatchingPattern(string $value): ?string
  {
    return $this->matcherService->findMatchingPattern($value);
  }
}
