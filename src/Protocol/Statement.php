<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

final class Statement
{
    private bool $closed = false;
    private mixed $beforeExecute = null;
    private mixed $afterExecute = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $id,
        private readonly string $sql,
        private readonly int $parameterCount,
        private readonly int $columnCount,
        private readonly int $warningCount,
    ) {
    }

    public function execute(array $parameters = [], ?float $timeout = null): array|bool
    {
        if ($this->closed) {
            throw new \LogicException('The prepared statement is closed');
        }
        if ($this->beforeExecute !== null) {
            ($this->beforeExecute)();
        }
        $result = $this->connection->executeStatement($this, $parameters, $timeout);
        if ($this->afterExecute !== null) {
            ($this->afterExecute)($result);
        }
        return $result;
    }

    public function setExecutionCallbacks(?callable $beforeExecute, ?callable $afterExecute): self
    {
        $this->beforeExecute = $beforeExecute;
        $this->afterExecute = $afterExecute;
        return $this;
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->connection->closeStatement($this->id);
            $this->closed = true;
        }
    }

    public function getId(): int { return $this->id; }
    public function getSql(): string { return $this->sql; }
    public function getParameterCount(): int { return $this->parameterCount; }
    public function getColumnCount(): int { return $this->columnCount; }
    public function getWarningCount(): int { return $this->warningCount; }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }
}
