<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/**
 * @internal 构造器写时复制的待构建状态默认值。
 * 使用数组保持 PHP 克隆隔离，无需子类调用 parent::__clone()。
 * @phpstan-type State array{
 *   transactionOp: ?int, joins: list<array>, whereConditions: list<array>,
 *   unionBranches: list<array>, joinConditions: array<int, array>, havingConditions: list<array>,
 *   ordering: array<array-key, array>, groups: list<mixed>, queryOptions: list<string>,
 *   updateColumns: ?array, tableName: mixed,
 *   forUpdate: bool, lockInShareMode: bool, selectLockOption: string|false,
 *   limit: mixed, fields: ?array, forceIndexes: list<string>, useIndexes: list<string>,
 *   ignoreIndexes: list<string>,
 *   fromSource: null|array{sql: string, params: array, alias: string}
 * }
 */
final class PendingQuery
{
    public const DEFAULTS = [
        'transactionOp' => null,
        'joins' => [], 'whereConditions' => [], 'unionBranches' => [],
        'joinConditions' => [], 'havingConditions' => [], 'ordering' => [], 'groups' => [],
        'queryOptions' => [], 'updateColumns' => null,
        'tableName' => '', 'forUpdate' => false, 'lockInShareMode' => false,
        'selectLockOption' => '', 'limit' => null, 'fields' => null,
        'forceIndexes' => [], 'useIndexes' => [], 'ignoreIndexes' => [],
        'fromSource' => null,
    ];

    /** @param State $state */
    public static function parts(array $state): QueryParts
    {
        return new QueryParts(
            joins: $state['joins'],
            joinConditions: $state['joinConditions'],
            where: $state['whereConditions'],
            groups: $state['groups'],
            having: $state['havingConditions'],
            unions: $state['unionBranches'],
            ordering: $state['ordering'],
            updateColumns: $state['updateColumns'],
            forUpdate: $state['forUpdate'],
            lockInShareMode: $state['lockInShareMode'],
            selectLockOption: $state['selectLockOption'],
        );
    }
}
