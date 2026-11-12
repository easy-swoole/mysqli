<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Expression;
use EasySwoole\Mysqli\QueryBuilder;

/**
 * @internal 编译 JOIN、GROUP、UNION、ORDER 及 LIMIT 子句。
 * @phpstan-type JoinNode array{0: string, 1: mixed, 2: mixed, 3: string|null}
 * @phpstan-type UnionNode array{0: string|null, 1: array<array-key, mixed>, 2: mixed, 3: bool}
 * @phpstan-type OrderNode array{field: string|Expression, direction: string, params: array<array-key, mixed>}
 * @phpstan-import-type ConditionNode from ConditionCompiler
 */
final class SelectCompiler
{
    public function __construct(private readonly SqlBuffer $buffer, private readonly ConditionCompiler $conditions) {}

    /**
     * @param array<array-key, JoinNode>|null $joins
     * @param array<array-key, array<array-key, ConditionNode>>|null $joinConditions
     */
    public function appendJoins(mixed $joins, mixed $joinConditions): void
    {
        $matched = [];
        foreach ($joins ?? [] as $data) {
            [$joinType, $joinTable, $joinCondition, $conditionKey] = $data;
            if ($joinTable instanceof QueryBuilder) {
                $source = SelectPlanner::subqueryReference($joinTable, derivedTable: true);
                $conditionKey = $source['alias'];
                $joinStr = '  ' . $source['sql'];
                $this->buffer->bindAll($source['params']);
            } elseif (is_object($joinTable)) {
                $joinStr = $this->conditions->pair('', $joinTable);
            } else {
                $joinStr = $joinTable;
            }
            $extra = $conditionKey === null ? [] : ($joinConditions[$conditionKey] ?? []);
            if ($extra !== []) {
                if (isset($matched[$conditionKey])) {
                    throw new \InvalidArgumentException('Ambiguous JOIN condition target: ' . $conditionKey);
                }
                $matched[$conditionKey] = true;
            }
            $using = is_string($joinCondition) && preg_match('/\A\s*USING\s*\(/i', $joinCondition) === 1;
            if ($joinType === JoinType::Natural->value) {
                if (!is_string($joinCondition) || trim($joinCondition) !== '' || $extra !== []) {
                    throw new \InvalidArgumentException('NATURAL joins require an empty join condition and cannot have additional ON conditions');
                }
                $this->buffer->sql .= ' NATURAL JOIN ' . $joinStr;
                continue;
            }
            if ($using && $extra !== []) {
                throw new \InvalidArgumentException('USING joins cannot have additional ON conditions');
            }
            if ($joinCondition instanceof Expression) {
                $this->buffer->sql .= ' ' . $joinType . ' JOIN ' . $joinStr . ' on (' . $joinCondition->getSql() . ')';
                $this->buffer->bindAll($joinCondition->getParameters());
            } else {
                $this->buffer->sql .= ' ' . $joinType . ' JOIN ' . $joinStr
                    . ($using ? ' ' : ' on ') . $joinCondition;
            }
            if ($extra !== []) {
                $this->conditions->appendOn($extra);
            }
        }
        $unmatched = array_diff_key($joinConditions ?? [], $matched);
        if ($unmatched !== []) {
            throw new \InvalidArgumentException('JOIN condition target was not joined: ' . array_key_first($unmatched));
        }
    }

    public function appendGroups(mixed $groups): void
    {
        if (empty($groups)) {
            return;
        }
        $this->buffer->sql .= ' GROUP BY ' . implode(', ', $groups) . ' ';
    }

    /** @param array<array-key, UnionNode>|null $unions */
    public function appendUnions(mixed $unions): void
    {
        if ($unions) {
            foreach ($unions as $unionData) {
                list($cond, $param, $isUnionAll, $parenthesize) = $unionData;
                $this->buffer->sql .= ' UNION ' . ($isUnionAll ? 'ALL ' : '')
                    . ($parenthesize ? '(' . $cond . ')' : $cond);
                $this->buffer->bindAll($param);
            }
        }
    }

    /** @param array<array-key, OrderNode>|null $ordering */
    public function appendOrder(mixed $ordering): void
    {
        if (empty($ordering)) {
            return;
        }
        $separator = ' ORDER BY ';
        foreach ($ordering as $order) {
            $this->buffer->sql .= $separator;
            $separator = ', ';
            $prop = $order['field'];
            $value = $order['direction'];
            if ($prop instanceof Expression) {
                $this->buffer->sql .= $prop->getSql() . ($value !== '' ? ' ' . $value : '');
                $this->buffer->bindAll($prop->getParameters());
                $this->buffer->bindAll($order['params']);
            } elseif (strtolower(str_replace(" ", "", $prop)) == 'rand()') {
                $this->buffer->sql .= 'rand()';
            } else {
                $this->buffer->sql .= $prop . ' ' . $value;
                $this->buffer->bindAll($order['params']);
            }
        }
        $this->buffer->sql .= ' ';
    }

    public function appendLimit(mixed $numRows): void
    {
        if ($numRows === null) {
            return;
        }
        if (is_array($numRows)) {
            if (array_keys($numRows) !== [0, 1]) {
                throw new \InvalidArgumentException('LIMIT requires [offset, row count]');
            }
            $limit = self::limitNumber($numRows[0]) . ', ' . self::limitNumber($numRows[1]);
        } else {
            $limit = self::limitNumber($numRows);
        }
        $this->buffer->sql .= ' LIMIT ' . $limit;
    }

    private static function limitNumber(mixed $value): string
    {
        if ((is_int($value) && $value >= 0) || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1)) {
            return (string)$value;
        }
        throw new \InvalidArgumentException('LIMIT values must be non-negative decimal integers');
    }

    /** SELECT 表达式的参数先于 JOIN、WHERE 等子句绑定。 */
    public static function columns(mixed $columns, SqlBuffer $buffer): string
    {
        if ($columns instanceof Expression) {
            $buffer->bindAll($columns->getParameters());
            return $columns->getSql();
        }
        if (!is_array($columns)) {
            return (string) $columns;
        }
        if ($columns === []) {
            throw new \InvalidArgumentException('SELECT requires at least one column');
        }
        $rendered = [];
        foreach ($columns as $column) {
            if ($column instanceof Expression) {
                $rendered[] = $column->getSql();
                $buffer->bindAll($column->getParameters());
            } else {
                $rendered[] = $column;
            }
        }
        return implode(', ', $rendered);
    }

    /** @return array{0: string|array<array-key, string>|null, 1: array{field: string|array<array-key, string>|null, direction: string, params: list<scalar|null>}} */
    public static function order(mixed $orderByField, mixed $orderbyDirection, mixed $customFieldsOrRegExp, mixed $tableName): array
    {
        $orderbyDirection = $orderbyDirection instanceof SortDirection ? $orderbyDirection->value : strtoupper(trim($orderbyDirection));
        $direction = SortDirection::tryFrom($orderbyDirection);
        if ($direction === null) {
            throw new Exception("Wrong order direction: {$orderbyDirection} for column {$orderByField} in table {$tableName}");
        }
        $orderParams = [];
        if (is_array($customFieldsOrRegExp)) {
            $orderParams = array_values($customFieldsOrRegExp);
            foreach ($orderParams as $value) {
                if ($value !== null && !is_scalar($value)) {
                    throw new \InvalidArgumentException('Custom sort values must be scalar values or null');
                }
            }
            if ($orderParams === []) {
                throw new \InvalidArgumentException('Custom sort values must not be empty');
            }
            $orderByField = 'FIELD (' . $orderByField . ', ' . implode(', ', array_fill(0, count($orderParams), '?')) . ')';
        } elseif (is_string($customFieldsOrRegExp)) {
            // 将正则模式作为值绑定；不同模式保留为独立排序项。
            $orderParams = [$customFieldsOrRegExp];
            $orderByField .= ' REGEXP ?';
        } elseif ($customFieldsOrRegExp !== null) {
            throw new Exception("Wrong custom field or Regular Expression: {$customFieldsOrRegExp} for column {$orderByField} in table {$tableName}");
        }
        $orderKey = $orderParams === [] ? $orderByField : serialize([$orderByField, $orderParams]);
        return [$orderKey, ['field' => $orderByField, 'direction' => $orderbyDirection, 'params' => $orderParams]];
        }
}
