<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal Snapshot of pending clauses; no access to the public builder state. */
final class QueryParts
{
    public function __construct(
        public readonly array $joins,
        public readonly array $joinConditions,
        public readonly array $where,
        public readonly array $groups,
        public readonly array $having,
        public readonly array $unions,
        public readonly array $ordering,
        public readonly mixed $updateColumns,
        public readonly bool $forUpdate,
        public readonly bool $lockInShareMode,
        public readonly mixed $selectLockOption
    ) {}
}
