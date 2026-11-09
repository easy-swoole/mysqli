<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\TransactionLostException;

/** @internal 服务端已确认的会话状态；传输细节由 PacketStream 负责。 */
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
        // InnoDB 在错误 1213 时回滚整个事务；错误 1205 通常仅影响当前语句。
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
