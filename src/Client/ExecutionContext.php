<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Client;

use EasySwoole\Mysqli\Protocol\{Deadline, Statement};

/** @internal 保存单次 Client 操作的共享超时预算、成功回调使用的开始时间和自建语句。 */
final class ExecutionContext
{
    private readonly Deadline $deadline;
    private readonly float $startedAt;
    /** @var \Closure(bool|array<array-key, mixed>, float): void */
    private readonly \Closure $notify;
    private ?Statement $statement = null;

    /** @param callable(bool|array<array-key, mixed>, float): void $notify */
    public function __construct(float $timeout, callable $notify)
    {
        $this->startedAt = microtime(true);
        $this->deadline = Deadline::after($timeout);
        $this->notify = \Closure::fromCallable($notify);
    }

    public function remaining(): float
    {
        return $this->deadline->remaining();
    }

    public function ownStatement(Statement $statement): Statement
    {
        return $this->statement = $statement;
    }

    /**
     * 缓冲查询在 finally 中释放自建语句，执行异常和成功回调异常均原样传播。
     * @param callable(): (bool|array<array-key, mixed>) $operation
     * @return bool|array<array-key, mixed>
     */
    public function buffered(callable $operation): bool|array
    {
        $failed = false;
        try {
            $result = $operation();
        } catch (\Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            if ($failed) {
                $this->discardOwnedStatement();
            } else {
                $this->closeOwnedStatement();
            }
        }
        $this->complete($result);
        return $result;
    }

    /** 释放语句；没有其他异常时由调用方收到清理错误。 */
    public function closeOwnedStatement(): void
    {
        $statement = $this->statement;
        $this->statement = null;
        $statement?->close($this->deadline->remainingOrZero());
    }

    /** 已有执行异常时尝试释放语句，保留原始异常。 */
    public function discardOwnedStatement(): void
    {
        try { $this->closeOwnedStatement(); } catch (\Throwable) {}
    }

    /** @param bool|array<array-key, mixed> $result */
    public function complete(bool|array $result): void
    {
        ($this->notify)($result, $this->startedAt);
    }

    /** 注册为自建语句结果流的关闭回调，在协议处理和元数据更新后执行。 */
    public function finishStream(bool $complete): void
    {
        $this->closeOwnedStatement();
        if ($complete) { $this->complete(true); }
    }
}
