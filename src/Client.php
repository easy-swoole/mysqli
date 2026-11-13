<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Client\ExecutionContext;
use EasySwoole\Mysqli\Protocol\Connection;
use EasySwoole\Mysqli\Protocol\Deadline;
use EasySwoole\Mysqli\Protocol\Statement;
use EasySwoole\Mysqli\Protocol\ResultStream;

class Client
{
    protected ?Connection $mysqlClient = null;
    /** @var (callable(bool|array<array-key, mixed>, Client, float): mixed)|null */
    protected mixed $onQuery = null;
    protected int|string|null $lastInsertId = null;
    protected int|string|null $lastAffectRows = null;

    /**
     * 保存数据库连接配置。
     */
    public function __construct(protected Config $config)
    {
    }

    /**
     * 设置查询成功后的回调。
     * @param callable(bool|array<array-key, mixed>, Client, float): mixed $callback
     */
    public function onQuery(callable $callback): static
    {
        $this->onQuery = $callback;
        return $this;
    }

    /**
     * 使用构造配置建立连接，可指定本次连接超时。
     */
    public function connect(?float $timeout = null): bool
    {
        if ($this->mysqlClient?->isBusy()) {
            throw new Exception('Concurrent operations on one MySQL connection are not allowed');
        }
        if ($this->mysqlClient?->isConnected()) {
            return $this->mysqlClient->connect($timeout);
        }
        $this->mysqlClient?->assertTransactionUsable();
        $this->mysqlClient = new Connection($this->config);
        try {
            return $this->mysqlClient->connect($timeout);
        } catch (\Throwable $error) {
            $this->mysqlClient = null;
            throw $error;
        }
    }

    /**
     * 通过服务端预处理执行构造器 SQL，表锁命令使用文本协议。
     * @return bool|list<array<array-key, mixed>>
     */
    public function query(QueryBuilder $builder,?float $timeout = null): bool|array
    {
        $sql = $builder->getLastPrepareQuery();
        if ($this->isTableLock($builder)) {
            return $this->rawQuery($sql, $timeout);
        }
        $parameters = $builder->getLastBindParams();
        $execution = $this->beginExecution($timeout);
        return $execution->buffered(function () use ($execution, $sql, $parameters): bool|array {
            $statement = $execution->ownStatement($this->prepare($sql, $execution->remaining()));
            return $statement->execute($parameters, $execution->remaining());
        });
    }

    /**
     * 按行读取构造器查询，Prepare、Execute、读取和清理共用一个超时预算。
     * 完整耗尽后 onQuery 收到 true；提前退出不触发成功回调并关闭连接。
     */
    public function iterate(QueryBuilder $builder, ?float $timeout = null): ResultStream
    {
        if ($this->isTableLock($builder)) {
            throw new \InvalidArgumentException('Table lock commands must be executed with query()');
        }
        $execution = $this->beginExecution($timeout);
        $sql = $builder->getLastPrepareQuery();
        $parameters = $builder->getLastBindParams();
        $statement = $execution->ownStatement($this->prepare($sql, $execution->remaining()));
        try {
            $stream = $statement->iterate($parameters, $execution->remaining());
        } catch (\Throwable $error) {
            $execution->discardOwnedStatement();
            throw $error;
        }
        return $stream->onClose([$execution, 'finishStream']);
    }

    /** 按行读取文本 SQL，完整耗尽后保存行数并执行 onQuery(true)。 */
    public function rawIterate(string $query, ?float $timeout = null): ResultStream
    {
        $execution = $this->beginExecution($timeout);
        return $this->connection()->iterate($query, $execution->remaining())->onComplete(function () use ($execution): void {
            $this->captureMetadata();
            $execution->complete(true);
        });
    }

    /**
     * 独立 COUNT 与数据查询共享总超时；需要一致性快照时由调用方开启事务。
     * @return array{items: list<array<array-key, mixed>>, total: int|string, page: int, perPage: int, lastPage: int|string}
     */
    public function paginate(QueryBuilder $query, ?string $table, int $perPage = 20, int $page = 1, ?float $timeout = null): array
    {
        $offset = \EasySwoole\Mysqli\QueryBuilder\Pagination::offset($perPage, $page);
        $deadline = $this->queryDeadline($timeout);
        $counter = clone $query;
        $data = clone $query;
        // 两条语句先构建完，避免查询回调或协程切换改变分页条件。
        $countQuery = $counter->count($table, '__mysqli_total');
        $dataQuery = $data->limit($offset, $perPage)->get($table);
        $counts = $this->query($countQuery, $deadline->remaining());
        $items = $this->query($dataQuery, $deadline->remaining());
        return \EasySwoole\Mysqli\QueryBuilder\Pagination::result($items, $counts[0]['__mysqli_total'], $perPage, $page);
    }

    /**
     * 通过文本协议执行 SQL 并读取结果。
     * @return bool|list<array<array-key, mixed>>
     */
    public function rawQuery(string $query,?float $timeout = null): bool|array
    {
        $execution = $this->beginExecution($timeout);
        return $execution->buffered(function () use ($execution, $query): bool|array {
            $result = $this->connection()->query($query, $execution->remaining());
            $this->captureMetadata();
            return $result;
        });
    }

    /**
     * 创建服务端预处理语句。
     */
    public function prepare(string $sql, ?float $timeout = null): Statement
    {
        $deadline = $this->queryDeadline($timeout);
        return $this->connection()->prepare($sql, $deadline->remaining())->setExecutionCallbacks(
            fn() => $this->resetMetadata(),
            fn() => $this->captureMetadata(),
        );
    }

    /**
     * 获取当前协议连接，未建立连接时返回 null。
     */
    public function mysqlClient(): Connection|null
    {
        return $this->mysqlClient;
    }

    /**
     * 检查当前连接是否可用。
     */
    public function ping(): bool
    {
        try {
            return $this->mysqlClient?->isConnected() === true && $this->mysqlClient->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 关闭连接并清除客户端连接引用。
     */
    public function close(): bool
    {
        $result = $this->mysqlClient?->close() ?? true;
        $this->mysqlClient = null;
        return $result;
    }

    /**
     * 获取最近一次查询的自增 ID；尚未查询或查询失败时返回 null。
     * MySQL 的无符号 64 位 ID 超过 PHP_INT_MAX 时返回十进制字符串，避免整数溢出或浮点精度丢失。
     */
    public function getLastInsertId(): int|string|null { return $this->lastInsertId; }

    /**
     * 获取最近一次查询的影响行数；尚未查询或查询失败时返回 null。
     * MySQL 协议使用无符号整数表示影响行数，超过 PHP_INT_MAX 时返回十进制字符串以保留精确值。
     * 普通查询通常返回 int；SELECT 返回当前客户端读取到的结果行数。
     */
    public function getLastAffectRows(): int|string|null { return $this->lastAffectRows; }

    /**
     * 对象销毁时尝试关闭连接。
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }

    private function isTableLock(QueryBuilder $builder): bool
    {
        $operation = $builder->getLastTransactionOp();
        return $operation === QueryBuilder::TS_OP_LOCK_TABLE || $operation === QueryBuilder::TS_OP_UNLOCK_TABLE;
    }

    /** 取出客户端持有的协议连接；连接和事务状态由协议层检查。 */
    private function connection(): Connection
    {
        return $this->mysqlClient ?? throw new Exception('MySQL connection is not open; call connect() first');
    }

    /**
     * 保存最近一次查询的插入 ID 和影响行数。
     */
    private function captureMetadata(): void
    {
        $this->lastInsertId = $this->mysqlClient?->insert_id;
        $this->lastAffectRows = $this->mysqlClient?->affected_rows;
    }

    /**
     * 清空客户端保存的查询元数据。
     */
    private function resetMetadata(): void
    {
        $this->lastInsertId = null;
        $this->lastAffectRows = null;
    }

    /**
     * 调用查询成功回调。
     */
    private function notify(array|bool $result, float $start): void
    {
        if ($this->onQuery !== null) {
            ($this->onQuery)($result, $this, $start);
        }
    }

    /** 先创建本次操作的超时预算，再清除元数据，保留无效超时参数的原有行为。 */
    private function beginExecution(?float $timeout): ExecutionContext
    {
        $execution = new ExecutionContext($timeout ?? $this->config->getTimeout(),
            function (bool|array $result, float $startedAt): void { $this->notify($result, $startedAt); });
        $this->resetMetadata();
        return $execution;
    }

    /**
     * 根据查询超时计算截止时间。
     */
    private function queryDeadline(?float $timeout): Deadline
    {
        return Deadline::after($timeout ?? $this->config->getTimeout());
    }
}
