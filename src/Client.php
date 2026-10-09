<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\TimeoutException;
use EasySwoole\Mysqli\Protocol\Connection;
use EasySwoole\Mysqli\Protocol\Statement;

class Client
{
    protected ?Connection $mysqlClient = null;
    protected mixed $onQuery = null;
    protected int|string|null $lastInsertId = null;
    protected int|string|null $lastAffectRows = null;

    public function __construct(protected Config $config)
    {
    }

    public function onQuery(callable $callback): static
    {
        $this->onQuery = $callback;
        return $this;
    }

    public function connect(?float $timeout = null): bool
    {
        return $this->connectWithTimeout($timeout);
    }

    private function connectWithTimeout(?float $timeout): bool
    {
        if ($this->mysqlClient?->isBusy()) {
            throw new Exception('Concurrent operations on one MySQL connection are not allowed');
        }
        if ($this->mysqlClient?->isConnected()) {
            return true;
        }
        $this->mysqlClient = new Connection($this->config);
        try {
            return $this->mysqlClient->connect($timeout);
        } catch (\Throwable $error) {
            $this->mysqlClient = null;
            throw $error;
        }
    }

    public function query(QueryBuilder $builder)
    {
        $timeout = func_get_args()[1] ?? null;
        $sql = $builder->getLastPrepareQuery();
        $parameters = $builder->getLastBindParams();
        $start = microtime(true);
        $deadline = $this->queryDeadline($timeout);
        $this->resetMetadata();
        $this->connectWithTimeout($this->remaining($deadline));
        $statement = null;
        try {
            $statement = $this->prepare($sql, $this->remaining($deadline));
            $result = $statement->execute($parameters, $this->remaining($deadline));
        } catch (Exception $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new Exception("SQL {$sql} failed: {$error->getMessage()}", (int) $error->getCode(), $error);
        } finally {
            $statement?->close(max(0.0, $deadline - microtime(true)));
        }
        $this->notify($result, $start);
        return $result;
    }

    public function rawQuery(string $query)
    {
        $timeout = func_get_args()[1] ?? null;
        $start = microtime(true);
        $deadline = $this->queryDeadline($timeout);
        $this->resetMetadata();
        $this->connectWithTimeout($this->remaining($deadline));
        try {
            $result = $this->mysqlClient->query($query, $this->remaining($deadline));
            $this->captureMetadata();
        } catch (Exception $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new Exception("SQL {$query} failed: {$error->getMessage()}", (int) $error->getCode(), $error);
        }
        $this->notify($result, $start);
        return $result;
    }

    public function prepare(string $sql, ?float $timeout = null): Statement
    {
        $deadline = $this->queryDeadline($timeout);
        $this->connectWithTimeout($this->remaining($deadline));
        return $this->mysqlClient->prepare($sql, $this->remaining($deadline))->setExecutionCallbacks(
            fn() => $this->resetMetadata(),
            fn() => $this->captureMetadata(),
        );
    }

    public function mysqlClient(): Connection|\mysqli|null
    {
        return $this->mysqlClient;
    }

    public function ping(): bool
    {
        try {
            return $this->mysqlClient?->isConnected() === true && $this->mysqlClient->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    public function close(): bool
    {
        $result = $this->mysqlClient?->close() ?? true;
        $this->mysqlClient = null;
        return $result;
    }

    public function getLastInsertId(): int|string|null { return $this->lastInsertId; }
    public function getLastAffectRows(): int|string|null { return $this->lastAffectRows; }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }

    private function captureMetadata(): void
    {
        $this->lastInsertId = $this->mysqlClient?->insert_id;
        $this->lastAffectRows = $this->mysqlClient?->affected_rows;
    }

    private function resetMetadata(): void
    {
        $this->lastInsertId = null;
        $this->lastAffectRows = null;
    }

    private function notify(array|bool $result, float $start): void
    {
        if ($this->onQuery !== null) {
            ($this->onQuery)($result, $this, $start);
        }
    }

    private function queryDeadline(?float $timeout): float
    {
        $duration = $timeout ?? $this->config->getTimeout();
        if ($duration <= 0) {
            throw new \InvalidArgumentException('Query timeout must be greater than zero');
        }
        return microtime(true) + $duration;
    }

    private function remaining(float $deadline): float
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new TimeoutException('MySQL query timed out', 110);
        }
        return $remaining;
    }
}
