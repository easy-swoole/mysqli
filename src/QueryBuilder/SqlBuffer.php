<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 保存单次编译的 SQL 及按顺序排列的参数。 */
final class SqlBuffer
{
    public ?string $column = null;
    public ?string $baseTableName = null;
    public ?self $countSource = null;
    public bool $unionOperandNeedsParentheses = true;

    /** @param array<array-key, mixed> $parameters */
    public function __construct(public string $sql, public array $parameters = [], public mixed $tableName = '') {}

    public function bind(mixed $value): void { $this->parameters[] = $value; }

    /** @param iterable<mixed> $values */
    public function bindAll(iterable $values): void
    {
        foreach ($values as $value) { $this->bind($value); }
    }
}
