<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\TimeoutException;

/** @internal A monotonic operation budget shared by connection, authentication and packet I/O. */
final class Deadline
{
    private function __construct(private readonly float $expiresAt)
    {
    }

    public static function after(float $seconds): self
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('Query timeout must be greater than zero');
        }
        return new self(self::now() + $seconds);
    }

    public function cappedBy(float $seconds): self
    {
        $cap = self::after($seconds);
        return new self(min($this->expiresAt, $cap->expiresAt));
    }

    public function remaining(): float
    {
        $remaining = $this->remainingOrZero();
        if ($remaining <= 0) {
            throw new TimeoutException('MySQL operation timed out', 110);
        }
        return $remaining;
    }

    public function remainingOrZero(): float
    {
        return max(0.0, $this->expiresAt - self::now());
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
