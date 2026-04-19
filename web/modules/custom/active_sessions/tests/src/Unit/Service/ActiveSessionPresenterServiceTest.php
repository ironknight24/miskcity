<?php

namespace Drupal\Tests\active_sessions\Unit\Service;

use Drupal\active_sessions\Service\ActiveSessionPresenterService;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @group active_sessions
 */
class ActiveSessionPresenterServiceTest extends UnitTestCase
{
    public function testPrepareSessionsFormatsCurrentAndOtherSessions(): void
    {
        $formatter = $this->createMock(DateFormatterInterface::class);
        $formatter->method('format')->willReturn('01-01-2023, 12:00:00');

        $service = new ActiveSessionPresenterService($formatter);
        [$current, $others] = $service->prepareSessions([
            ['id' => '1', 'loginTime' => 1234567000, 'userAgent' => 'Chrome Windows'],
            ['id' => '2', 'loginTime' => 1234568000, 'userAgent' => 'iPhone Safari'],
            ['id' => '3', 'userAgent' => 'Unknown'],
        ], 1234567, 'access-token');

        $this->assertCount(1, $current);
        $this->assertCount(2, $others);
        $this->assertSame('access-token', $current[0]['accessToken']);
        $this->assertSame('Chrome, Desktop (Windows)', $current[0]['userAgentFormatted']);
        $this->assertSame('Safari, Mobile (iPhone)', $others[0]['userAgentFormatted']);
        $this->assertSame('Unknown', $others[1]['userAgentFormatted']);
    }

    public function testPrepareSessionsHandlesMissingLoginTime(): void
    {
        $formatter = $this->createMock(DateFormatterInterface::class);
        $formatter->method('format')->willReturn('formatted');

        $service = new ActiveSessionPresenterService($formatter);
        [$current, $others] = $service->prepareSessions([
            ['id' => '3', 'userAgent' => 'PlainAgent'],
        ], NULL, 'token');

        $this->assertSame([], $current);
        $this->assertSame('PlainAgent', $others[0]['userAgentFormatted']);
    }
}
