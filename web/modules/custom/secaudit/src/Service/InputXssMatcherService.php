<?php

declare(strict_types=1);

namespace Drupal\secaudit\Service;

class InputXssMatcherService
{
  /**
   * @var string[]
   */
  protected array $xssPatterns = [
    '/<script\b[^>]*>(.*?)<\/script>/is',
    '/on\w+\s*=/i',
    '/javascript\s*:/i',
    '/\b(alert|eval|confirm|prompt)\s*\(/i',
    '/document\.cookie/i',
    '/<img\b[^>]*on\w+\s*=/i',
    '/<iframe\b/i',
    '/<svg\b[^>]*>/i',
    '/srcdoc\s*=/i',
    '/data\s*:\s*text\/html/i',
    '/data\s*:\s*text\/javascript/i',
    '/"\\s*<\\w|\'\\s*<\\w/',
  ];

  public function findMatchingPattern(string $value): ?string
  {
    foreach ($this->generateVariants($value) as $variant) {
      foreach ($this->xssPatterns as $pattern) {
        if (preg_match($pattern, $variant)) {
          return $pattern;
        }
      }
    }

    return null;
  }

  protected function generateVariants(string $value): array
  {
    return [
      $value,
      rawurldecode($value),
      html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
      rawurldecode(rawurldecode($value)),
      html_entity_decode(
        html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
      ),
    ];
  }
}
