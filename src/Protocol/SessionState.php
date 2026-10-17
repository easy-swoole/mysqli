<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\TransactionLostException;

/** @internal Server-confirmed session state; transport details belong to PacketStream. */
final class SessionState
{
    private const SERVER_STATUS_IN_TRANS = 0x0001;

    private bool $connected = false;
    private bool $inTransaction = false;
    private bool $transactionLost = false;
    private int $generation = 0;

    public function established(): void
    {
        $this->assertUsable();
        $this->connected = true;
        $this->generation++;
    }

    public function disconnected(bool $intentional = false): void
    {
        $this->transactionLost = !$intentional && ($this->transactionLost || $this->inTransaction);
        $this->connected = false;
        $this->inTransaction = false;
    }

    public function applyServerStatus(int $status): void
    {
        $this->inTransaction = ($status & self::SERVER_STATUS_IN_TRANS) !== 0;
    }

    public function applyServerError(int $code): void
    {
        // InnoDB rolls back the complete transaction for 1213; 1205 normally affects only the statement.
        if ($code === 1213) {
            $this->inTransaction = false;
        }
    }

    public function isConnected(): bool { return $this->connected; }
    public function inTransaction(): bool { return $this->connected && $this->inTransaction; }
    public function isTransactionLost(): bool { return $this->transactionLost; }
    public function generation(): int { return $this->generation; }

    public function assertUsable(): void
    {
        if ($this->transactionLost) {
            throw new TransactionLostException('MySQL transaction was lost; explicitly close the connection before starting a new session');
        }
    }
}
