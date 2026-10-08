<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests\Support;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\QueryBuilder;

/**
 * Captures the public calls made by EasySwoole FastDb without depending on it.
 */
final class FastDbStyleConnection extends Client
{
    public bool $isInTransaction = false;

    public function query(QueryBuilder $builder)
    {
        return parent::query($builder);
    }

    public function rawQuery(string $query)
    {
        return parent::rawQuery($query);
    }

    public function begin(int $flags = 0): bool
    {
        $result = $this->mysqlClient()->begin_transaction($flags);
        $this->isInTransaction = $result;
        return $result;
    }

    public function commit(int $flags = 0): bool
    {
        $result = $this->mysqlClient()->commit($flags);
        if ($result) {
            $this->isInTransaction = false;
        }
        return $result;
    }

    public function rollback(int $flags = 0): bool
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
