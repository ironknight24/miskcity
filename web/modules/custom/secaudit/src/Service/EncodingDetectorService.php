<?php

declare(strict_types=1);

namespace Drupal\secaudit\Service;

/**
 * Detects unexpected encodings in strings.
 */
class EncodingDetectorService
{
  /**
   * Returns the first matching unexpected encoding reason.
   */
  public function detectUnexpectedEncodingReason(string $value): ?string
  {
    $foundReason = null;
    $checks = [
      'isMixedEncoding' => 'mixed_encoding_styles',
      'isHexEncoding' => 'hex_encoding',
      'isUnicodeEscapeEncoding' => 'unicode_escape_encoding',
      'isOctalEncoding' => 'octal_encoding',
      'isMultiByteNullPadding' => 'multi_byte_null_padding',
      'isBinaryOrControlCharacters' => 'binary_or_control_characters',
    ];

    foreach ($checks as $method => $label) {
      if ($this->$method($value)) {
        $foundReason = $label;
        break;
      }
    }

    return $foundReason;
  }

  protected function isMixedEncoding(string $value): bool
  {
    return preg_match('/%[0-9a-fA-F]{2}/', $value)
      && (preg_match('/\\\\x[0-9a-fA-F]{2}/', $value) || preg_match('/\\\\u[0-9a-fA-F]{4}/', $value));
  }

  protected function isHexEncoding(string $value): bool
  {
    return preg_match('/\\\\x[0-9a-fA-F]{2}/', $value) === 1;
  }

  protected function isUnicodeEscapeEncoding(string $value): bool
  {
    return preg_match('/\\\\u[0-9a-fA-F]{4}/', $value) === 1;
  }

  protected function isOctalEncoding(string $value): bool
  {
    return preg_match('/\\\\[0-7]{2,3}/', $value) === 1;
  }

  protected function isMultiByteNullPadding(string $value): bool
  {
    return preg_match('/\x00.\x00/', $value) === 1 || preg_match('/.\x00.\x00/', $value) === 1;
  }

  protected function isBinaryOrControlCharacters(string $value): bool
  {
    return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1;
  }

  public function hasDoubleEncoding(string $value): bool
  {
    $once = rawurldecode($value);
    $twice = rawurldecode($once);
    return $twice !== $once || $this->containsHTMLEntity($twice);
  }

  public function hasHtmlDoubleEncoding(string $value): bool
  {
    $once = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $twice = html_entity_decode($once, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $twice !== $once;
  }

  protected function containsHTMLEntity(string $value): bool
  {
    return preg_match('/&(lt|gt|amp|quot|apos|#\d+);/', $value) === 1;
  }
}
