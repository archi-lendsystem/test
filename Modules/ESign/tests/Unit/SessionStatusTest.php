<?php

namespace Modules\ESign\Tests\Unit;

use Modules\ESign\Enums\SessionStatus;
use PHPUnit\Framework\TestCase;

final class SessionStatusTest extends TestCase
{
    public function test_status_flags(): void
    {
        $statuses = [
            [SessionStatus::Initiating, true, false],
            [SessionStatus::Pending, true, false],
            [SessionStatus::Viewed, true, false],
            [SessionStatus::Signed, false, true],
            [SessionStatus::Failed, false, true],
            [SessionStatus::Expired, false, true],
            [SessionStatus::Cancelled, false, true],
        ];

        foreach ($statuses as [$status, $active, $terminal]) {
            self::assertSame($active, $status->isActive());
            self::assertSame($terminal, $status->isTerminal());
        }
    }
}
