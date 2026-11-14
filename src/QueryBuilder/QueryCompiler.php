<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\QueryBuilder;

/** @internal 按语句类型及参数绑定顺序协调各子句编译器。 */
final class QueryCompiler
{
    // 按 MySQL 语法顺序列出各语句可用的修饰符。
    private const MODIFIERS = [
        'ALL' => ['SELECT'],
        'DISTINCT' => ['SELECT'],
        'DISTINCTROW' => ['SELECT'],
        'LOW_PRIORITY' => ['INSERT', 'REPLACE', 'UPDATE', 'DELETE'],
        'HIGH_PRIORITY' => ['SELECT', 'INSERT'],
        'STRAIGHT_JOIN' => ['SELECT'],
        'SQL_SMALL_RESULT' => ['SELECT'],
        'SQL_BIG_RESULT' => ['SELECT'],
        'SQL_BUFFER_RESULT' => ['SELECT'],
        'SQL_NO_CACHE' => ['SELECT'],
        'SQL_CALC_FOUND_ROWS' => ['SELECT'],
        'QUICK' => ['DELETE'],
        'IGNORE' => ['INSERT', 'UPDATE', 'DELETE'],
    ];

    public static function knowsOption(string $option): bool
    {
        return isset(self::MODIFIERS[$option]) || $option === 'FOR UPDATE' || $option === 'LOCK IN SHARE MODE';
    }

    public static function modifierSql(string $statement, array $options): string
    {
        if ($options === []) {
            return '';
        }
        foreach ($options as $option) {
            if (!in_array($statement, self::MODIFIERS[$option] ?? [], true)) {
                throw new \InvalidArgumentException($option . ' is not supported for ' . $statement);
            }
        }
        $selected = array_fill_keys($options, true);
        if (count(array_intersect_key($selected, ['ALL' => true, 'DISTINCT' => true, 'DISTINCTROW' => true])) > 1
            || (isset($selected['LOW_PRIORITY'], $selected['HIGH_PRIORITY']))) {
            throw new \InvalidArgumentException('Conflicting modifiers for ' . $statement);
        }
        return implode(' ', array_keys(array_intersect_key(self::MODIFIERS, $selected)));
    }

    /**
     * @param 'INSERT'|'REPLACE'|'UPDATE'|'DELETE' $statement
     * @param array{sql: string, name: string, target: ?string, baseName: ?string} $table
     */
    public static function writeHead(string $statement, array $table, array $options, bool $hasJoins): SqlBuffer
    {
        $target = '';
        if ($statement === 'DELETE' && $hasJoins) {
            if ($table['target'] === null) {
                throw new \InvalidArgumentException('DELETE with JOIN requires an identifiable target table');
            }
            $target = $table['target'] . ' ';
        }
        $modifiers = self::modifierSql($statement, $options);
        $sql = match ($statement) {
            'INSERT', 'REPLACE' => $statement . ' ' . $modifiers . ' INTO ' . $table['sql'],
            'UPDATE' => rtrim('UPDATE ' . $modifiers) . ' ' . $table['sql'],
            'DELETE' => rtrim('DELETE ' . $modifiers) . ' ' . $target . 'FROM ' . $table['sql'],
        };
        $buffer = new SqlBuffer($sql, tableName: $table['name']);
        $buffer->baseTableName = $table['baseName'];
        return $buffer;
    }

    /** @param array{bool, bool} $quoteMode */
    public function compileSelect(
        SqlBuffer $buffer,
        QueryParts $parts,
        ?string $projection,
        array $options,
        mixed $numRows,
        array $quoteMode,
        bool $countOnly,
        ?CountCompilation $scope,
    ): CompiledQuery {
        $conditions = new ConditionCompiler($buffer);
        $select = new SelectCompiler($buffer, $conditions);
        $beforeUnionAndTail = $this->appendSelectBody($buffer, $parts, $select, $conditions, $countOnly);
        if ($countOnly || $scope !== null) {
            // 计数取到 UNION 为止，分支和子查询已经编译好的排序及分页仍然保留。
            $count = $this->countSource($buffer, $parts, $projection, $options, $quoteMode);
            if ($countOnly) {
                return $count;
            }
        }

        $operation = $this->appendSelectTail($buffer, $parts, $select, $numRows, $beforeUnionAndTail);
        $buffer->sql = self::selectHead($projection ?? '*', $options) . $buffer->sql;
        $query = CompiledQuery::fromBuffer($buffer, $options, $operation, $quoteMode);
        if ($scope !== null) {
            $scope->sources[$query] = $count;
        }
        return $query;
    }

    private function appendSelectBody(
        SqlBuffer $buffer,
        QueryParts $parts,
        SelectCompiler $select,
        ConditionCompiler $conditions,
        bool $countOnly,
    ): int {
        if ($parts->updateColumns !== null && $parts->updateColumns !== []) {
            throw new \InvalidArgumentException('ON DUPLICATE KEY UPDATE requires INSERT');
        }
        if (!$countOnly) {
            if ($parts->forUpdate && $parts->lockInShareMode) {
                throw new \InvalidArgumentException('FOR UPDATE and LOCK IN SHARE MODE cannot be combined');
            }
            if ($parts->unions !== [] && ($parts->forUpdate || $parts->lockInShareMode)) {
                throw new \InvalidArgumentException('UNION row locks must be set on individual query branches');
            }
        }

        $select->appendJoins($parts->joins);
        $conditions->append('WHERE', $parts->where);
        $select->appendGroups($parts->groups);
        $conditions->append('HAVING', $parts->having);
        $beforeUnionAndTail = strlen($buffer->sql);
        $select->appendUnions($parts->unions);
        return $beforeUnionAndTail;
    }

    private function appendSelectTail(
        SqlBuffer $buffer,
        QueryParts $parts,
        SelectCompiler $select,
        mixed $numRows,
        int $beforeUnionAndTail,
    ): ?int {
        $select->appendOrder($parts->ordering);
        $select->appendLimit($numRows);
        $operation = null;
        if ($parts->forUpdate) {
            $buffer->sql .= ' FOR UPDATE';
            if ($parts->selectLockOption !== '') {
                $buffer->sql .= ' ' . $parts->selectLockOption;
            }
            $operation = QueryBuilder::TS_OP_LOCK_FOR_UPDATE;
        } elseif ($parts->lockInShareMode) {
            $buffer->sql .= ' LOCK IN SHARE MODE';
            $operation = QueryBuilder::TS_OP_LOCK_IN_SHARE;
        }
        $buffer->unionOperandNeedsParentheses = strlen($buffer->sql) !== $beforeUnionAndTail;
        return $operation;
    }

    /** @param array{bool, bool} $quoteMode */
    private function countSource(
        SqlBuffer $buffer,
        QueryParts $parts,
        ?string $projection,
        array $options,
        array $quoteMode,
    ): CompiledQuery {
        // 顶层 SELECT 的专用选项不能带进计数派生表。
        $options = array_values(array_diff($options, ['SQL_CALC_FOUND_ROWS', 'SQL_BUFFER_RESULT', 'HIGH_PRIORITY']));
        if ($projection === null) {
            $usesProjection = $parts->groups !== [] || $parts->having !== [] || $parts->unions !== []
                || array_intersect(['DISTINCT', 'DISTINCTROW'], $options) !== [];
            $projection = $usesProjection ? '*' : '1';
        }
        return new CompiledQuery(self::selectHead($projection, $options) . $buffer->sql,
            $buffer->parameters, $options, null, quoteMode: $quoteMode);
    }

    private static function selectHead(string $projection, array $options): string
    {
        return 'SELECT ' . self::modifierSql('SELECT', $options) . ' ' . $projection;
    }

    /** @param 'INSERT'|'REPLACE'|'UPDATE'|'DELETE' $statement */
    public function compile(
        SqlBuffer $buffer,
        QueryParts $parts,
        string $statement,
        mixed $numRows,
        mixed $tableData,
        ?array $fields,
        bool $batch
    ): ?int {
        if ($parts->groups !== [] || $parts->having !== [] || $parts->unions !== []
            || $parts->forUpdate || $parts->lockInShareMode) {
            throw new \InvalidArgumentException('GROUP BY, HAVING, UNION and row locks require SELECT');
        }
        if ($statement !== 'INSERT' && $parts->updateColumns !== null && $parts->updateColumns !== []) {
            throw new \InvalidArgumentException('ON DUPLICATE KEY UPDATE requires INSERT');
        }

        if ($statement === 'INSERT' || $statement === 'REPLACE') {
            if ($parts->joins !== [] || $parts->where !== []
                || $parts->ordering !== [] || $numRows !== null) {
                throw new \InvalidArgumentException('INSERT and REPLACE do not support JOIN, WHERE, ORDER BY or LIMIT');
            }
            (new WriteCompiler($buffer))->appendInsert($tableData, $fields, $batch, $parts->updateColumns);
            return null;
        }

        if ($parts->joins !== [] && ($parts->ordering !== [] || $numRows !== null)) {
            throw new \InvalidArgumentException('UPDATE and DELETE with JOIN do not support ORDER BY or LIMIT');
        }
        if (is_array($numRows)) {
            throw new \InvalidArgumentException('UPDATE and DELETE LIMIT accept a row count only');
        }

        $conditions = new ConditionCompiler($buffer);
        $select = new SelectCompiler($buffer, $conditions);
        $select->appendJoins($parts->joins);
        if ($statement === 'UPDATE') {
            (new WriteCompiler($buffer))->appendUpdate($tableData, $fields);
        }
        $conditions->append('WHERE', $parts->where);
        $select->appendOrder($parts->ordering);
        $select->appendLimit($numRows);
        return null;
    }
}
