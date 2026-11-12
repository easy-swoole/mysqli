<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\QueryBuilder;

/** @internal 按语句类型及参数绑定顺序协调各子句编译器。 */
final class QueryCompiler
{
    /** @param 'SELECT'|'INSERT'|'REPLACE'|'UPDATE'|'DELETE' $statement */
    public function compile(
        SqlBuffer $buffer,
        QueryParts $parts,
        string $statement,
        mixed $numRows,
        mixed $tableData,
        ?array $fields,
        bool $batch
    ): ?int {
        $isSelect = $statement === 'SELECT';
        $isInsert = $statement === 'INSERT' || $statement === 'REPLACE';
        if (!$isSelect && ($parts->groups !== [] || $parts->having !== [] || $parts->unions !== []
            || $parts->forUpdate || $parts->lockInShareMode)) {
            throw new \InvalidArgumentException('GROUP BY, HAVING, UNION and row locks require SELECT');
        }
        if ($statement !== 'INSERT' && is_array($parts->updateColumns) && $parts->updateColumns !== []) {
            throw new \InvalidArgumentException('ON DUPLICATE KEY UPDATE requires INSERT');
        }

        if ($parts->forUpdate && $parts->lockInShareMode) {
            throw new \InvalidArgumentException('FOR UPDATE and LOCK IN SHARE MODE cannot be combined');
        }

        $conditions = new ConditionCompiler($buffer);
        if ($isInsert) {
            if ($parts->joins !== [] || $parts->joinConditions !== [] || $parts->where !== []
                || $parts->ordering !== [] || $numRows !== null) {
                throw new \InvalidArgumentException('INSERT and REPLACE do not support JOIN, WHERE, ORDER BY or LIMIT');
            }
            $write = new WriteCompiler($buffer, $conditions);
            $inserted = $write->appendData($tableData, true, $fields, $batch);
            if ($statement === 'INSERT') {
                $write->appendDuplicate($inserted, $parts->updateColumns);
            }
            return null;
        }

        if (!$isSelect) {
            if ($parts->joins !== [] && ($parts->ordering !== [] || $numRows !== null)) {
                throw new \InvalidArgumentException('UPDATE and DELETE with JOIN do not support ORDER BY or LIMIT');
            }
            if (is_array($numRows)) {
                throw new \InvalidArgumentException('UPDATE and DELETE LIMIT accept a row count only');
            }
        }

        $select = new SelectCompiler($buffer, $conditions);
        $select->appendJoins($parts->joins, $parts->joinConditions);
        if ($statement === 'UPDATE') {
            (new WriteCompiler($buffer, $conditions))->appendData($tableData, false, $fields);
        }
        $conditions->append('WHERE', $parts->where);
        if ($isSelect) {
            $select->appendGroups($parts->groups);
            $conditions->append('HAVING', $parts->having);
            $select->appendUnions($parts->unions);
        }
        $select->appendOrder($parts->ordering);
        $select->appendLimit($numRows);

        if ($parts->forUpdate) {
            $buffer->sql .= $parts->selectLockOption ? ' FOR UPDATE ' . $parts->selectLockOption : ' FOR UPDATE';
            return QueryBuilder::TS_OP_LOCK_FOR_UPDATE;
        }
        if ($parts->lockInShareMode) {
            $buffer->sql .= ' LOCK IN SHARE MODE';
            return QueryBuilder::TS_OP_LOCK_IN_SHARE;
        }
        return null;
    }
}
