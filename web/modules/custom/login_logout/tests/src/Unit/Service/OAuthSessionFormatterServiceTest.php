<?php

namespace Drupal\Tests\login_logout\Unit\Service;

use Drupal\login_logout\Service\OAuthSessionFormatterService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\login_logout\Service\OAuthSessionFormatterService
 * @group login_logout
 */
class OAuthSessionFormatterServiceTest extends UnitTestCase
{
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OAuthSessionFormatterService();
    }

    /**
     * @covers ::formatSessions
     * @covers ::formatSessionEntry
     */
    public function testFormatSessions(): void
    {
        $sessions = [
            ['browser' => 'Chrome', 'device' => 'PC', 'lastAccessTime' => 1600000000000],
        ];
        $output = $this->service->formatSessions($sessions);
        $this->assertStringContainsString('Chrome', $output);
        $this->assertStringContainsString('PC', $output);
    }
}
