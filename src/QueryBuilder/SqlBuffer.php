<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal Ordered SQL and parameter output for one compilation. */
final class SqlBuffer
{
    public ?string $column = null;

    /** @param array<array-key, mixed> $parameters */
    public function __construct(public string $sql, public array $parameters = [], public mixed $tableName = '') {}

    public function bind(mixed $value): void { $this->parameters[] = $value; }

    /** @param iterable<mixed> $values */
    public function bindAll(mixed $values): void
    {
        foreach ($values as $value) { $this->bind($value); }
    }
}
