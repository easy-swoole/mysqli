<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Expression;

/**
 * @internal Compile JOIN, GROUP, UNION, ORDER and LIMIT clauses.
 * @phpstan-type JoinNode array{0: string, 1: mixed, 2: mixed, 3?: string|null}
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
        if (empty ($joins))
            return;
        foreach ($joins as $data) {
            list ($joinType, $joinTable, $joinCondition) = $data;
            if (is_object($joinTable))
                $joinStr = $this->conditions->pair("", $joinTable);
            else
                $joinStr = $joinTable;
            $using = is_string($joinCondition) && preg_match('/\A\s*USING\s*\(/i', $joinCondition) === 1;
            $conditionKey = $data[3] ?? $joinStr;
            if ($joinType === JoinType::Natural->value) {
                if (!is_string($joinCondition) || trim($joinCondition) !== '' || !empty($joinConditions[$conditionKey])) {
                    throw new \InvalidArgumentException('NATURAL joins require an empty join condition and cannot have additional ON conditions');
                }
                $this->buffer->sql .= ' NATURAL JOIN ' . $joinStr;
                continue;
            }
            if ($using && !empty($joinConditions[$conditionKey])) {
                throw new \InvalidArgumentException('USING joins cannot have additional ON conditions');
            }
            if ($joinCondition instanceof Expression) {
                $this->buffer->sql .= ' ' . $joinType . ' JOIN ' . $joinStr . ' on (' . $joinCondition->getSql() . ')';
                $this->buffer->bindAll($joinCondition->getParameters());
            } else {
                $this->buffer->sql .= " " . $joinType . " JOIN " . $joinStr .
                    ($using ? " " : " on ")
                    . $joinCondition;
            }
            if (!empty($joinConditions) && isset($joinConditions[$conditionKey])) {
                $this->conditions->appendOn($joinConditions[$conditionKey]);
            }
        }
    }

    public function appendGroups(mixed $groups): void
    {
        if (empty($groups)) {
            return;
        }
        $this->buffer->sql .= " GROUP BY ";
        foreach ($groups as $key => $value) {
            $this->buffer->sql .= $value . ", ";
        }
        $this->buffer->sql = rtrim($this->buffer->sql, ', ') . " ";
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
        $this->buffer->sql .= " ORDER BY ";
        foreach ($ordering as $order) {
            $prop = $order['field'];
            $value = $order['direction'];
            if ($prop instanceof Expression) {
                $this->buffer->sql .= $prop->getSql() . ($value !== '' ? ' ' . $value : '') . ', ';
                $this->buffer->bindAll($prop->getParameters());
                $this->buffer->bindAll($order['params']);
            } elseif (strtolower(str_replace(" ", "", $prop)) == 'rand()') {
                $this->buffer->sql .= "rand(), ";
            } else {
                $this->buffer->sql .= $prop . " " . $value . ", ";
                $this->buffer->bindAll($order['params']);
            }
        }
        $this->buffer->sql = rtrim($this->buffer->sql, ', ') . " ";
    }

    public function appendLimit(mixed $numRows): void
    {
        if (!isset($numRows)) {
            return;
        }
        if (is_array($numRows)) {
            $this->buffer->sql .= ' LIMIT ' . (int)$numRows[0] . ', ' . (int)$numRows[1];
        } else {
            $this->buffer->sql .= ' LIMIT ' . (int)$numRows;
        }
    }

    /** SELECT expressions bind before JOIN, WHERE and other clauses. */
    public static function columns(mixed $columns, SqlBuffer $buffer): string
    {
        if ($columns instanceof Expression) {
            $buffer->bindAll($columns->getParameters());
            return $columns->getSql();
        }
        if (!is_array($columns)) {
            return (string) $columns;
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
    public static function order(mixed $orderByField, mixed $orderbyDirection, mixed $customFieldsOrRegExp, mixed $prefix, mixed $tableName): array
    {
        $orderbyDirection = $orderbyDirection instanceof SortDirection ? $orderbyDirection->value : strtoupper(trim($orderbyDirection));
        $direction = SortDirection::tryFrom($orderbyDirection);
        $orderByField = preg_replace("/[^ -a-z0-9\.\(\),_`\*\'\"%`]+/i", '', $orderByField);
        // Add table prefix to orderByField if needed.
        //FIXME: We are adding prefix only if table is enclosed into `` to distinguish aliases
        // from table names
        $orderByField = preg_replace('/(\`)([`a-zA-Z0-9_%`\']*\.)/', '\1' . $prefix . '\2', $orderByField);
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
            // Keep the pattern as a value, never as SQL text. Distinct patterns retain distinct order entries.
            $orderParams = [$customFieldsOrRegExp];
            $orderByField .= ' REGEXP ?';
        } elseif ($customFieldsOrRegExp !== null) {
            throw new Exception("Wrong custom field or Regular Expression: {$customFieldsOrRegExp} for column {$orderByField} in table {$tableName}");
        }
        $orderKey = $orderParams === [] ? $orderByField : serialize([$orderByField, $orderParams]);
        return [$orderKey, ['field' => $orderByField, 'direction' => $orderbyDirection, 'params' => $orderParams]];
        }
}
