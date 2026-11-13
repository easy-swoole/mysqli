<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Expression;
use EasySwoole\Mysqli\QueryBuilder;

/**
 * @internal 无状态 SELECT 查询规划；公开构造器保留工厂及可重写方法的调用。
 * @phpstan-import-type State from PendingQuery
 */
final class SelectPlanner
{
    /** @return array{sql: string, params: array, alias: string} */
    public static function fromSub(?string $sql, array $parameters, string $alias): array
    {
        if ($sql === null || preg_match('/\A\s*(?:SELECT\b|WITH\b|\()/i', $sql) !== 1) {
            throw new \InvalidArgumentException('FROM requires a built SELECT query');
        }
        $quotedAlias = TableReference::identifier($alias);
        return ['sql' => SqlList::parenthesized($sql) . ' AS ' . $quotedAlias,
            'params' => array_map(static fn($value) => $value, $parameters), 'alias' => $alias];
    }

    /**
     * JOIN 使用带别名的派生表，条件和写入值使用无别名的子查询。
     * @return array{sql: string, params: array, alias: ?string}
     */
    public static function subqueryReference(QueryBuilder $builder, bool $derivedTable): array
    {
        $query = $builder->getSubQuery();
        $sql = $query['query'] ?? null;
        if ($sql === null || trim($sql) === '') {
            throw new \InvalidArgumentException('Use a built QueryBuilder::subQuery()');
        }
        $alias = $query['alias'];
        $sql = SqlList::parenthesized($sql);
        if ($derivedTable) {
            if ($alias === null || $alias === '') {
                throw new \InvalidArgumentException('JOIN subqueries require an alias');
            }
            $sql .= ' ' . TableReference::identifier($alias);
        } elseif ($alias !== null && $alias !== '') {
            throw new \InvalidArgumentException('Condition and value subqueries must not have an alias');
        }
        return ['sql' => $sql, 'params' => $query['params'], 'alias' => $alias];
    }

    /** @param null|array{sql: string, params: array, alias: string} $fromSource */
    public static function selectBuffer(
        mixed $tableName,
        mixed $columns,
        array $options,
        mixed $prefix,
        ?array $fromSource,
        array $indexHints,
    ): SqlBuffer {
        $select = new SqlBuffer('', tableName: $tableName);
        $column = SelectCompiler::columns($columns, $select);
        $table = $tableName;
        if ($fromSource !== null) {
            if ($tableName !== null) {
                throw new \InvalidArgumentException('Use get(null) after fromSub(), or getFromSub()');
            }
            foreach ($indexHints as $indexes) {
                if ($indexes !== []) { throw new \InvalidArgumentException('Index hints belong inside the FROM subquery'); }
            }
            $tableSql = $fromSource['sql'];
            $table = $fromSource['alias'];
            $select->bindAll($fromSource['params']);
        } else {
            if (!is_string($tableName) || trim($tableName) === '') {
                throw new \InvalidArgumentException('SELECT requires a table or a FROM subquery');
            }
            $tableSql = TableReference::parse($table, $prefix);
        }
        $select->sql = 'SELECT ' . QueryCompiler::modifierSql('SELECT', $options) . ' ' . $column . ' FROM ' . $tableSql
            . TableReference::indexHints($indexHints);
        $select->tableName = $table;
        return $select;
    }

    /**
     * 转换写时复制的状态副本，供 COUNT 编译内层 SELECT。
     * @param State $state
     * @return State
     */
    public static function countSource(array $state): array
    {
        $state['ordering'] = [];
        $state['limit'] = null;
        $state['forUpdate'] = false;
        $state['lockInShareMode'] = false;
        $state['selectLockOption'] = '';
        // 这些选项用于顶层 SELECT，不能带进计数派生表。
        $state['queryOptions'] = array_values(array_diff(
            $state['queryOptions'], ['SQL_CALC_FOUND_ROWS', 'SQL_BUFFER_RESULT', 'HIGH_PRIORITY']
        ));
        // 只有普通行计数的隐式投影可以换成常量。分组和集合查询沿用调用方的投影。
        if ($state['fields'] === null && $state['groups'] === [] && $state['havingConditions'] === []
            && $state['unionBranches'] === []
            && array_intersect(['DISTINCT', 'DISTINCTROW'], $state['queryOptions']) === []) {
            $state['fields'] = ['1'];
        }
        return $state;
    }

    public static function countSql(string $sourceSql, string $quotedAlias): string
    {
        return 'SELECT COUNT(*) AS ' . $quotedAlias . ' FROM ' . SqlList::parenthesized($sourceSql)
            . ' AS `__mysqli_count` LIMIT 1';
    }

    public static function aggregateExpression(string $function, string|Expression $column, string $alias, bool $hasUnion): Expression
    {
        $function = strtoupper(trim($function));
        if (!in_array($function, ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'], true)) {
            throw new \InvalidArgumentException('Unsupported aggregate function');
        }
        if ($hasUnion) {
            throw new \InvalidArgumentException('Use fromSub() to aggregate an entire UNION');
        }
        $sql = $column instanceof Expression ? $column->getSql()
            : ($column === '*' && $function === 'COUNT' ? '*' : TableReference::column($column));
        $parameters = $column instanceof Expression ? $column->getParameters() : [];
        return new Expression($function . '(' . $sql . ') AS ' . TableReference::identifier($alias), $parameters);
    }

}
