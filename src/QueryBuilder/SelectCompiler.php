<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Exception\Exception;

/** @internal Compile JOIN, GROUP, UNION, ORDER and LIMIT clauses. */
final class SelectCompiler
{
    public function __construct(private readonly SqlBuffer $buffer, private readonly ConditionCompiler $conditions) {}

    public function appendJoins($joins, $joinConditions): void
    {
        if (empty ($joins))
            return;
        foreach ($joins as $data) {
            list ($joinType, $joinTable, $joinCondition) = $data;
            if (is_object($joinTable))
                $joinStr = $this->conditions->pair("", $joinTable);
            else
                $joinStr = $joinTable;
            $this->buffer->sql .= " " . $joinType . " JOIN " . $joinStr .
                (false !== stripos($joinCondition, 'using') ? " " : " on ")
                . $joinCondition;
            // Add join and query
            if (!empty($joinConditions) && isset($joinConditions[$joinStr])) {
                foreach ($joinConditions[$joinStr] as $join_and_cond) {
                    list ($concat, $varName, $operator, $val) = $join_and_cond;
                    $this->buffer->sql .= " " . $concat . " " . $varName;
                    $this->conditions->appendJoinValue($operator, $val);
                }
            }
        }
    }

    public function appendGroups($groups): void
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

    public function appendUnions($unions): void
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

    public function appendOrder($ordering): void
    {
        if (empty($ordering)) {
            return;
        }
        $this->buffer->sql .= " ORDER BY ";
        foreach ($ordering as $order) {
            $prop = $order['field'];
            $value = $order['direction'];
            if (strtolower(str_replace(" ", "", $prop)) == 'rand()') {
                $this->buffer->sql .= "rand(), ";
            } else {
                $this->buffer->sql .= $prop . " " . $value . ", ";
                $this->buffer->bindAll($order['params']);
            }
        }
        $this->buffer->sql = rtrim($this->buffer->sql, ', ') . " ";
    }

    public function appendLimit($numRows): void
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

    public static function order($orderByField, $orderbyDirection, $customFieldsOrRegExp, $prefix, $tableName): array
    {
        $allowedDirection = ["ASC", "DESC"];
        $orderbyDirection = strtoupper(trim($orderbyDirection));
        $orderByField = preg_replace("/[^ -a-z0-9\.\(\),_`\*\'\"%`]+/i", '', $orderByField);
        // Add table prefix to orderByField if needed.
        //FIXME: We are adding prefix only if table is enclosed into `` to distinguish aliases
        // from table names
        $orderByField = preg_replace('/(\`)([`a-zA-Z0-9_%`\']*\.)/', '\1' . $prefix . '\2', $orderByField);
        if (empty($orderbyDirection) || !in_array($orderbyDirection, $allowedDirection)) {
            throw new Exception("Wrong order direction: {$orderbyDirection} for column {$orderByField} in table {$tableName}");
        }
        $orderParams = [];
        if (is_array($customFieldsOrRegExp)) {
            foreach ($customFieldsOrRegExp as $key => $value) {
                $customFieldsOrRegExp[$key] = preg_replace("/[^\x80-\xff-a-z0-9\.\(\),_` %\']+/i", '', $value);
            }
            $orderByField = 'FIELD (' . $orderByField . ', "' . implode('","', $customFieldsOrRegExp) . '")';
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
