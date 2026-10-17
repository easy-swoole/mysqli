<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Exception\TransactionLostException;
use EasySwoole\Mysqli\Protocol\SessionState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionStateTest extends TestCase
{
    public function testTransactionLossSurvivesRepeatedDisconnectsUntilExplicitClose(): void
    {
        $state = new SessionState();
        self::assertFalse($state->isConnected());
        self::assertSame(0, $state->generation());
        $state->established();
        $state->applyServerStatus(3);
        self::assertTrue($state->inTransaction());
        $state->disconnected();
        $state->disconnected();
        self::assertFalse($state->isConnected());
        self::assertFalse($state->inTransaction());
        self::assertTrue($state->isTransactionLost());
        try {
            $state->established();
            self::fail('Lost transaction must not silently reconnect');
        } catch (TransactionLostException) {
            self::assertSame(1, $state->generation());
        }
        $state->disconnected(true);
        self::assertFalse($state->isTransactionLost());
        $state->established();
        self::assertSame(2, $state->generation());
        self::assertTrue($state->isConnected());
    }

    public function testIdleDisconnectCanStartAnotherSession(): void
    {
        $state = new SessionState();
        $state->established();
        $state->applyServerStatus(2);
        $state->disconnected();
        self::assertFalse($state->isTransactionLost());
        $state->established();
        self::assertSame(2, $state->generation());
    }

    #[DataProvider('serverErrors')]
    public function testServerErrorTransitionPreservesUsableSession(int $code, bool $inTransaction): void
    {
        $state = new SessionState();
        $state->established();
        $state->applyServerStatus(3);
        $state->applyServerError($code);
        self::assertSame($inTransaction, $state->inTransaction());
        self::assertTrue($state->isConnected());
        self::assertFalse($state->isTransactionLost());
    }

    public static function serverErrors(): array
    {
        return ['deadlock' => [1213, false], 'lock wait timeout' => [1205, true], 'duplicate key' => [1062, true]];
    }

    public function testCompletedTransactionDoesNotBecomeLostOnRelease(): void
    {
        $state = new SessionState();
        $state->established();
        $state->applyServerStatus(3);
        $state->applyServerStatus(2);
        $state->disconnected();
        self::assertFalse($state->isTransactionLost());
        self::assertFalse($state->inTransaction());
    }
}
