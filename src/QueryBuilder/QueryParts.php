<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/**
 * @internal Snapshot of pending clauses; no access to the public builder state.
 * @phpstan-import-type ConditionNode from ConditionCompiler
 * @phpstan-import-type JoinNode from SelectCompiler
 * @phpstan-import-type UnionNode from SelectCompiler
 * @phpstan-import-type OrderNode from SelectCompiler
 */
final class QueryParts
{
    /**
     * @param array<array-key, JoinNode> $joins
     * @param array<array-key, array<array-key, ConditionNode>> $joinConditions
     * @param array<array-key, ConditionNode> $where
     * @param array<array-key, mixed> $groups
     * @param array<array-key, ConditionNode> $having
     * @param array<array-key, UnionNode> $unions
     * @param array<array-key, OrderNode> $ordering
     */
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
