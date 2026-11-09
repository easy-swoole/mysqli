<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Expression;

/**
 * @internal Stateless SELECT planning; the public builder retains factories and virtual calls.
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

    /** @param null|array{sql: string, params: array, alias: string} $fromSource */
    public static function selectHead(
        mixed $tableName,
        mixed $columns,
        array $options,
        mixed $prefix,
        ?array $fromSource,
        array $indexHints,
    ): SelectPlan {
        $select = new SqlBuffer('', [], $tableName);
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
        $sql = 'SELECT ' . implode(' ', $options) . ' ' . $column . ' FROM ' . $tableSql
            . TableReference::indexHints($indexHints);
        return new SelectPlan($sql, $select->parameters, $table);
    }

    /**
     * Transform a COW state copy; the facade still clones itself and calls the clone's get().
     * @param State $state
     * @return array{state: State, quotedAlias: string}
     */
    public static function countInput(array $state, string $alias): array
    {
        $quotedAlias = TableReference::identifier($alias);
        $state['ordering'] = [];
        $state['limit'] = null;
        $state['forUpdate'] = false;
        $state['lockInShareMode'] = false;
        $state['selectLockOption'] = '';
        $state['queryOptions'] = array_values(array_diff($state['queryOptions'], ['SQL_CALC_FOUND_ROWS']));
        if ($state['fields'] === '*' || $state['fields'] === ['*']) {
            if ($state['groups'] !== [] && $state['unionBranches'] === []) {
                $items = [];
                foreach ($state['groups'] as $group) { array_push($items, ...SqlList::items($group)); }
                $hasOrdinal = false;
                foreach ($items as $item) {
                    if (preg_match('/\A\s*[0-9]+\s*\z/', $item) === 1) { $hasOrdinal = true; break; }
                }
                if (!$hasOrdinal) {
                    $state['fields'] = [];
                    foreach ($items as $item) {
                        $state['fields'][] = new Expression($item . ' AS `__mysqli_group' . count($state['fields']) . '`');
                    }
                }
            } elseif ($state['unionBranches'] === [] && $state['havingConditions'] === []
                && array_intersect(['DISTINCT', 'DISTINCTROW'], $state['queryOptions']) === []) {
                $state['fields'] = [new Expression('1 AS `__mysqli_row`')];
            }
        }
        return ['state' => $state, 'quotedAlias' => $quotedAlias];
    }

    /** The alias comes from countInput(); parameters remain in the completed source's SQL order. */
    public static function countSql(string $sourceSql, string $quotedAlias): string
    {
        return 'SELECT COUNT(*) AS ' . $quotedAlias . ' FROM ' . SqlList::parenthesized($sourceSql)
            . ' AS `__mysqli_count`';
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

    /**
     * Return only normalized inputs; cursor predicates and sorting still use facade methods.
     * @param State $state
     * @return array{where: array, quotedColumn: string}
     */
    public static function keysetInput(array $state, string $column, mixed $lastId, int $size): array
    {
        if ($size < 1) { throw new \InvalidArgumentException('Page size must be positive'); }
        if ($lastId !== null && !is_int($lastId) && !is_string($lastId)) {
            throw new \InvalidArgumentException('Cursor must be an integer, string or null');
        }
        $quoted = TableReference::column($column);
        if ($state['groups'] !== [] || $state['havingConditions'] !== [] || $state['unionBranches'] !== []
            || $state['fromSource'] !== null || $state['forUpdate'] || $state['lockInShareMode']
            || array_intersect(['DISTINCT', 'DISTINCTROW', 'SQL_CALC_FOUND_ROWS'], $state['queryOptions']) !== []) {
            throw new \InvalidArgumentException('Keyset pages require a plain SELECT without grouping, distinct, union or locks');
        }
        return ['where' => self::keysetWhere($state['whereConditions']), 'quotedColumn' => $quoted];
    }

    /** Group the clone's current predicates after the facade has invoked the subclass's __clone(). */
    public static function keysetWhere(array $where): array
    {
        return $where === [] ? [] : [['group' => $where, 'concat' => '']];
    }
}
