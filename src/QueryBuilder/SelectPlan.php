<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal Immutable SELECT header and parameters, before the remaining clauses are compiled. */
final class SelectPlan
{
    public function __construct(
        public readonly string $sql,
        public readonly array $parameters,
        public readonly string $tableName,
    ) {}
}
