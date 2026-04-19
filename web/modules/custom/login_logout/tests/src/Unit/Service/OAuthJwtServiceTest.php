<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\login_logout\Service\OAuthJwtService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\OAuthJwtService
 * @group login_logout
 */
class OAuthJwtServiceTest extends UnitTestCase
{
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OAuthJwtService();
    }

    /**
     * @covers ::isValidJwtFormat
     */
    public function testIsValidJwtFormat(): void
    {
        $this->assertTrue($this->service->isValidJwtFormat('a.b.c'));
        $this->assertFalse($this->service->isValidJwtFormat('a.b'));
    }

    /**
     * @covers ::extractPayloadFromJwt
     */
    public function testExtractPayloadFromJwt(): void
    {
        $this->assertSame('b', $this->service->extractPayloadFromJwt('a.b.c'));
        $this->assertSame('', $this->service->extractPayloadFromJwt('a'));
    }

    /**
     * @covers ::decodeBase64Url
     */
    public function testDecodeBase64Url(): void
    {
        $data = ['user' => 'john'];
        $encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($data)));
        $this->assertSame($data, $this->service->decodeBase64Url($encoded));
    }
}
