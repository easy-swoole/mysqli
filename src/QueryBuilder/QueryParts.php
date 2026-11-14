<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/**
 * @internal 待构建子句的快照；不直接访问公开构造器的状态。
 * @phpstan-import-type ConditionNode from ConditionCompiler
 * @phpstan-import-type JoinNode from SelectCompiler
 * @phpstan-import-type UnionNode from SelectCompiler
 * @phpstan-import-type OrderNode from SelectCompiler
 */
final class QueryParts
{
    /**
     * @param list<JoinNode> $joins
     * @param array<array-key, ConditionNode> $where
     * @param array<array-key, mixed> $groups
     * @param array<array-key, ConditionNode> $having
     * @param array<array-key, UnionNode> $unions
     * @param array<array-key, OrderNode> $ordering
     */
    public function __construct(
        public readonly array $joins,
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
