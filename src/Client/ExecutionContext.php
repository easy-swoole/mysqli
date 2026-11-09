<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Client;

use EasySwoole\Mysqli\Protocol\{Deadline, Statement};
use EasySwoole\Mysqli\Exception\Exception;

/** @internal One Client operation's shared budget, success timestamp and owned statement. */
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
        if ($this->statement !== null) {
            throw new \LogicException('An execution context already owns a statement');
        }
        return $this->statement = $statement;
    }

    /**
     * Buffered paths share SQL error wrapping and finally cleanup; observer errors stay outside it.
     * @param callable(): (bool|array<array-key, mixed>) $operation
     * @return bool|array<array-key, mixed>
     */
    public function buffered(?string $sql, callable $operation): bool|array
    {
        try {
            $result = $operation();
        } catch (Exception $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new Exception("SQL {$sql} failed: {$error->getMessage()}", (int) $error->getCode(), $error);
        } finally {
            $this->closeOwnedStatement();
        }
        $this->complete($result);
        return $result;
    }

    /** Buffered execution keeps its existing finally semantics: cleanup errors propagate. */
    public function closeOwnedStatement(): void
    {
        $this->statement?->close($this->deadline->remainingOrZero());
        $this->statement = null;
    }

    /** Stream startup failures keep their original error even if cleanup also fails. */
    public function discardOwnedStatement(): void
    {
        try { $this->closeOwnedStatement(); } catch (\Throwable) {}
    }

    /** @param bool|array<array-key, mixed> $result */
    public function complete(bool|array $result): void
    {
        ($this->notify)($result, $this->startedAt);
    }

    /** Registered as an owned stream's close callback, after protocol/metadata completion. */
    public function finishStream(bool $complete): void
    {
        $this->closeOwnedStatement();
        if ($complete) { $this->complete(true); }
    }
}
