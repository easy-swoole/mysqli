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
    public static function selectHead(
        mixed $tableName,
        mixed $columns,
        array $options,
        mixed $prefix,
        ?array $fromSource,
        array $indexHints,
    ): SelectPlan {
        $select = self::selectBuffer($tableName, $columns, $options, $prefix, $fromSource, $indexHints);
        return new SelectPlan($select->sql, $select->parameters, $select->tableName);
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
        $select->projection = $column;
        return $select;
    }

    /**
     * 转换写时复制的状态副本，供 COUNT 编译内层 SELECT。
     * @param State $state
     * @return State
     */
    public static function countSource(array $state): array
    {
        return self::countSourceWithQuoteMode($state, false);
    }

    /** @param State $state @return State */
    public static function countSourceWithQuoteMode(array $state, bool $noBackslashEscapes): array
    {
        return self::countSourceForMode($state, $noBackslashEscapes, false);
    }

    /** @param State $state @return State */
    public static function countSourceForMode(array $state, bool $noBackslashEscapes, bool $ansiQuotes): array
    {
        $state['ordering'] = [];
        $state['limit'] = null;
        $state['forUpdate'] = false;
        $state['lockInShareMode'] = false;
        $state['selectLockOption'] = '';
        $state['queryOptions'] = array_values(array_diff($state['queryOptions'], ['SQL_CALC_FOUND_ROWS']));
        // 显式投影可能包含 HAVING 引用的字段或别名。DISTINCT 和 UNION 依赖原投影。
        if (($state['fields'] !== null && $state['fields'] !== ['*'])
            || $state['unionBranches'] !== []
            || array_intersect(['DISTINCT', 'DISTINCTROW'], $state['queryOptions']) !== []) {
            return $state;
        }
        if ($state['groups'] !== []) {
            // 分组计数默认只投影分组表达式；显式 * 可供 HAVING 引用其他字段。
            if ($state['fields'] === null || $state['havingConditions'] === []) {
                $state['fields'] = self::groupProjection($state['groups'], $noBackslashEscapes, $ansiQuotes) ?? $state['fields'];
            }
        } elseif ($state['havingConditions'] === []) {
            $state['fields'] = [new Expression('1 AS `__mysqli_row`')];
        }
        return $state;
    }

    /** 生成仅包含分组表达式的投影；按列位置分组时保留原投影。 */
    private static function groupProjection(array $groups, bool $noBackslashEscapes, bool $ansiQuotes): ?array
    {
        $last = array_key_last($groups);
        // WITH ROLLUP 修饰整条 GROUP BY，仅分组表达式可用作投影。
        $groups[$last] = preg_replace('/\s+WITH\s+ROLLUP\s*\z/i', '', $groups[$last]);
        $fields = [];
        foreach ($groups as $group) {
            // ROLLUP(...) 写法只取括号内的分组表达式。
            if (preg_match('/\A\s*ROLLUP\s*\((.*)\)\s*\z/is', $group, $rollup) === 1) {
                $group = $rollup[1];
            }
            foreach (SqlList::splitForMode($group, $noBackslashEscapes, $ansiQuotes) as $item) {
                if (preg_match('/\A\s*[0-9]+\s*\z/', $item) === 1) {
                    return null;
                }
                $fields[] = new Expression($item . ' AS `__mysqli_group' . count($fields) . '`');
            }
        }
        return $fields;
    }

    /** 参数保持已构建来源查询中的 SQL 顺序。 */
    public static function countSql(string $sourceSql, string $quotedAlias): string
    {
        return self::countSqlForProjection($sourceSql, $quotedAlias, null, false, false);
    }

    public static function countSqlForProjection(
        string $sourceSql, string $quotedAlias, ?string $projection, bool $noBackslashEscapes, bool $ansiQuotes
    ): string {
        $columnList = '';
        if ($projection !== null) {
            $items = SqlList::splitForMode($projection, $noBackslashEscapes, $ansiQuotes);
            $names = [];
            foreach ($items as $index => $item) {
                // 星号由服务端展开，列数在编译时未知。
                if (preg_match('/(?:^|\.)\s*\*\s*\z/', trim($item)) === 1) {
                    $names = [];
                    break;
                }
                $names[] = '`__mysqli_col' . $index . '`';
            }
            if ($names !== []) {
                $columnList = ' (' . implode(', ', $names) . ')';
            }
        }
        return 'SELECT COUNT(*) AS ' . $quotedAlias . ' FROM ' . SqlList::parenthesized($sourceSql)
            . ' AS `__mysqli_count`' . $columnList . ' LIMIT 1';
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
