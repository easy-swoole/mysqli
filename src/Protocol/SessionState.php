<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\TransactionLostException;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;

/** @internal 服务端已确认的会话状态；传输细节由 PacketStream 负责。 */
final class SessionState
{
    private const SERVER_STATUS_IN_TRANS = 0x0001;

    private bool $connected = false;
    private bool $inTransaction = false;
    private bool $unconfirmedChainedTransaction = false;
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
        $this->transactionLost = !$intentional
            && ($this->transactionLost || ($this->inTransaction && !$this->unconfirmedChainedTransaction));
        $this->connected = false;
        $this->inTransaction = false;
        $this->unconfirmedChainedTransaction = false;
    }

    public function applyServerStatus(int $status): void
    {
        $this->inTransaction = ($status & self::SERVER_STATUS_IN_TRANS) !== 0;
        $this->confirmSession();
    }

    public function applyServerError(int $code): void
    {
        $this->confirmSession();
        // InnoDB 在错误 1213 时回滚整个事务；错误 1205 通常仅影响当前语句。
        if ($code === 1213) {
            $this->inTransaction = false;
        }
    }

    /** 完整命令响应确认会话仍可用。 */
    public function confirmSession(): void
    {
        $this->unconfirmedChainedTransaction = false;
    }

    /** 旧事务已成功结束。若服务端报告新事务，等下一条响应确认会话仍可用。 */
    public function transactionCompleted(): void
    {
        $this->unconfirmedChainedTransaction = $this->inTransaction;
    }

    /** true 表示显式释放，false 表示由服务端决定，null 表示无需额外处理。 */
    public static function completionReleasesSession(string $sql): ?bool
    {
        return TransactionCompletionFlags::sqlReleasePolicy($sql);
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
