<?php

namespace Drupal\Tests\secaudit\Unit\Service;

use Drupal\secaudit\Service\EncodingDetectorService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\secaudit\Service\EncodingDetectorService
 * @group secaudit
 */
class EncodingDetectorServiceTest extends UnitTestCase
{
  protected $service;

  protected function setUp(): void
  {
    parent::setUp();
    $this->service = new EncodingDetectorService();
  }

  /**
   * @covers ::detectUnexpectedEncodingReason
   */
  public function testDetectUnexpectedEncodingReason(): void
  {
    $this->assertSame('mixed_encoding_styles', $this->service->detectUnexpectedEncodingReason('%20\x41'));
    $this->assertSame('hex_encoding', $this->service->detectUnexpectedEncodingReason('\x41'));
    $this->assertSame('unicode_escape_encoding', $this->service->detectUnexpectedEncodingReason('\u0041'));
    $this->assertSame('octal_encoding', $this->service->detectUnexpectedEncodingReason('\101'));
    $this->assertSame('multi_byte_null_padding', $this->service->detectUnexpectedEncodingReason("\x00A\x00"));
    $this->assertSame('binary_or_control_characters', $this->service->detectUnexpectedEncodingReason("\x01"));
    $this->assertNull($this->service->detectUnexpectedEncodingReason('normal string'));
  }

  /**
   * @covers ::hasDoubleEncoding
   * @covers ::hasHtmlDoubleEncoding
   */
  public function testDoubleEncodings(): void
  {
    $this->assertTrue($this->service->hasDoubleEncoding('%2520'));
    $this->assertFalse($this->service->hasDoubleEncoding('normal'));
    $this->assertTrue($this->service->hasHtmlDoubleEncoding('&amp;lt;'));
    $this->assertFalse($this->service->hasHtmlDoubleEncoding('&lt;'));
  }
}
