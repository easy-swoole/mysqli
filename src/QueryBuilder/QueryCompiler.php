<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\QueryBuilder;

/** @internal Coordinate clause compilers in SQL and binding order. */
final class QueryCompiler
{
    public function compile(SqlBuffer $buffer, QueryParts $parts, $numRows, $tableData): ?int
    {
        $conditions = new ConditionCompiler($buffer);
        $select = new SelectCompiler($buffer, $conditions);
        $write = new WriteCompiler($buffer, $conditions);
        $select->appendJoins($parts->joins, $parts->joinConditions);
        $write->appendData($tableData);
        $conditions->append('WHERE', $parts->where);
        $select->appendGroups($parts->groups);
        $conditions->append('HAVING', $parts->having);
        $select->appendUnions($parts->unions);
        $select->appendOrder($parts->ordering);
        $select->appendLimit($numRows);
        $write->appendDuplicate($tableData, $parts->updateColumns);
        $operation = null;
        if ($parts->forUpdate) {
            $operation = QueryBuilder::TS_OP_LOCK_FOR_UPDATE;
            $buffer->sql .= $parts->selectLockOption ? ' FOR UPDATE ' . $parts->selectLockOption : ' FOR UPDATE';
        }
        if ($parts->lockInShareMode) {
            $operation = QueryBuilder::TS_OP_LOCK_IN_SHARE;
            $buffer->sql .= ' LOCK IN SHARE MODE';
        }
        return $operation;
    }
}
