<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal Ordered SQL and parameter output for one compilation. */
final class SqlBuffer
{
    public ?string $column = null;

    public function __construct(public string $sql, public array $parameters = [], public $tableName = '') {}

    public function bind($value): void { $this->parameters[] = $value; }

    public function bindAll($values): void
    {
        foreach ($values as $value) { $this->bind($value); }
    }
}
