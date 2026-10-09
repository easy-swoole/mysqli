<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

final class Statement
{
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
    }

    /**
     * 绑定参数并执行预处理语句。
     */
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
        if (!$this->closed) {
            $this->connection->closeStatement($this->id, $this->generation, $timeout);
            $this->closed = true;
        }
    }

    /**
     * 获取语句所属连接的会话代次。
     */
    public function getGeneration(): int { return $this->generation; }
    /**
     * 获取服务端分配的语句 ID。
     */
    public function getId(): int { return $this->id; }
    /**
     * 获取预处理语句的原始 SQL。
     */
    public function getSql(): string { return $this->sql; }
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
