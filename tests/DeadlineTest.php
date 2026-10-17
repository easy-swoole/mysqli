<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Exception\TimeoutException;
use EasySwoole\Mysqli\Protocol\Deadline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeadlineTest extends TestCase
{
    public function testCappingCannotExtendTheParentBudget(): void
    {
        $deadline = Deadline::after(1.0);
        $before = $deadline->remaining();
        $capped = $deadline->cappedBy(10.0);
        self::assertLessThanOrEqual($before, $capped->remaining());
        self::assertGreaterThan(0.0, $capped->remaining());
    }

    public function testShorterCapDoesNotChangeTheOriginalBudget(): void
    {
        $deadline = Deadline::after(10.0);
        $capped = $deadline->cappedBy(1.0);
        self::assertLessThanOrEqual(1.0, $capped->remaining());
        self::assertGreaterThan(5.0, $deadline->remaining());
    }

    public function testExpiredDeadlineCannotBeRevivedByAnotherCap(): void
    {
        $deadline = Deadline::after(0.001);
        usleep(2000);
        self::assertSame(0.0, $deadline->remainingOrZero());
        self::assertSame(0.0, $deadline->cappedBy(10.0)->remainingOrZero());
        try {
            $deadline->remaining();
            self::fail('Expired operation must time out');
        } catch (TimeoutException $error) {
            self::assertSame(110, $error->getCode());
        }
    }

    #[DataProvider('invalidDurations')]
    public function testNonPositiveTimeoutsAreRejected(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Deadline::after($seconds);
    }

    public static function invalidDurations(): array
    {
        return ['zero' => [0.0], 'negative' => [-0.1]];
    }
}
