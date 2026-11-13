<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;

final class Statement
{
    private readonly ?bool $releaseOnCompletion;
    private bool $closed = false;
    private mixed $beforeExecute = null;
    private mixed $afterExecute = null;

    /**
     * 保存预处理语句信息及所属连接会话。
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly int $id,
        private readonly string $sql,
        private readonly int $parameterCount,
        private readonly int $columnCount,
        private readonly int $warningCount,
        private readonly int $generation = 0,
    ) {
        $this->releaseOnCompletion = TransactionCompletionFlags::sqlReleasePolicy($this->sql);
    }

    /**
     * 绑定参数并执行预处理语句。
     */
    public function execute(array $parameters = [], ?float $timeout = null): array|bool
    {
        return $this->executeWithin($parameters, $timeout);
    }

    /** @internal 沿用当前操作的绝对截止时间。 */
    public function executeWithDeadline(array $parameters, Deadline $deadline): array|bool
    {
        return $this->executeWithin($parameters, $deadline);
    }

    private function executeWithin(array $parameters, Deadline|float|null $timeout): array|bool
    {
        if ($this->closed) {
            throw new \LogicException('The prepared statement is closed');
        }
        if ($this->beforeExecute !== null) {
            ($this->beforeExecute)();
        }
        $result = $this->connection->executeWithDeadline($this, $parameters, $timeout);
        if ($this->afterExecute !== null) {
            ($this->afterExecute)($result);
        }
        return $result;
    }

    /**
     * 按行读取结果，完整耗尽后语句仍可复用；提前退出会关闭所属连接。
     * 完整耗尽时执行 afterExecute(true)，提前停止或读取失败不执行成功回调。
     */
    public function iterate(array $parameters = [], ?float $timeout = null): ResultStream
    {
        return $this->iterateWithin($parameters, $timeout);
    }

    /** @internal 沿用当前操作的绝对截止时间。 */
    public function iterateWithDeadline(array $parameters, Deadline $deadline): ResultStream
    {
        return $this->iterateWithin($parameters, $deadline);
    }

    private function iterateWithin(array $parameters, Deadline|float|null $timeout): ResultStream
    {
        if ($this->closed) {
            throw new \LogicException('The prepared statement is closed');
        }
        if ($this->beforeExecute !== null) { ($this->beforeExecute)(); }
        $stream = $this->connection->iterateStatementWithDeadline($this, $parameters, $timeout);
        if ($this->afterExecute !== null) {
            $afterExecute = $this->afterExecute;
            $stream->onComplete(static fn() => $afterExecute(true));
        }
        return $stream;
    }

    /**
     * 设置语句执行前后的回调。
     */
    public function setExecutionCallbacks(?callable $beforeExecute, ?callable $afterExecute): self
    {
        $this->beforeExecute = $beforeExecute;
        $this->afterExecute = $afterExecute;
        return $this;
    }

    /**
     * 释放服务端预处理语句，失效会话中的语句不发送关闭命令。
     */
    public function close(?float $timeout = null): void
    {
        $this->closeWithin($timeout);
    }

    /** @internal 沿用当前操作的绝对截止时间。 */
    public function closeWithDeadline(Deadline $deadline): void
    {
        $this->closeWithin($deadline);
    }

    private function closeWithin(Deadline|float|null $timeout): void
    {
        if (!$this->closed) {
            $this->connection->closeStatementWithDeadline($this->id, $this->generation, $timeout);
            $this->closed = true;
        }
    }

    /**
     * 获取语句所属连接的会话代次。
     */
    public function getGeneration(): int { return $this->generation; }

    /** @internal 核对语句是否由指定连接创建。 */
    public function belongsTo(Connection $connection): bool { return $this->connection === $connection; }
    /**
     * 获取服务端分配的语句 ID。
     */
    public function getId(): int { return $this->id; }
    /**
     * 获取预处理语句的原始 SQL。
     */
    public function getSql(): string { return $this->sql; }
    /** @internal 在准备语句时确定事务结束语义，执行时直接复用。 */
    public function completionReleasesSession(): ?bool { return $this->releaseOnCompletion; }

    /**
     * 获取语句需要的参数数量。
     */
    public function getParameterCount(): int { return $this->parameterCount; }
    /**
     * 获取预处理结果的字段数量。
     */
    public function getColumnCount(): int { return $this->columnCount; }
    /**
     * 获取预处理阶段的警告数量。
     */
    public function getWarningCount(): int { return $this->warningCount; }

    /**
     * 对象销毁时尝试释放预处理语句。
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }
}
