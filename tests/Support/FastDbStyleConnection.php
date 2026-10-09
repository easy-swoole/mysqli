<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests\Support;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\QueryBuilder;

/**
 * Exercises FastDb-style calls with subclasses adapted to the current Client API.
 */
final class FastDbStyleConnection extends Client
{
    public bool $isInTransaction = false;

    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        return parent::query($builder, $timeout);
    }

    public function rawQuery(string $query, ?float $timeout = null)
    {
        return parent::rawQuery($query, $timeout);
    }

    public function begin(TransactionStartFlags $flags = TransactionStartFlags::None): bool
    {
        $result = $this->mysqlClient()->begin_transaction($flags);
        $this->isInTransaction = $result;
        return $result;
    }

    public function commit(TransactionCompletionFlags $flags = TransactionCompletionFlags::None): bool
    {
        $result = $this->mysqlClient()->commit($flags);
        if ($result) {
            $this->isInTransaction = false;
        }
        return $result;
    }

    public function rollback(TransactionCompletionFlags $flags = TransactionCompletionFlags::None): bool
    {
        $result = $this->mysqlClient()->rollback($flags);
        if ($result) {
            $this->isInTransaction = false;
        }
        return $result;
    }

    public function beforeUse(): bool
    {
        try {
            return $this->mysqlClient()->query('SELECT 1') !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
