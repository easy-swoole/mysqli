<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Expression;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Exception\Exception;

/** @internal 编译 INSERT/REPLACE 值、UPDATE 赋值及重复键更新。 */
final class WriteCompiler
{
    /** @var list<string>|null */
    private ?array $batchColumns = null;

    public function __construct(private readonly SqlBuffer $buffer, private readonly ConditionCompiler $conditions) {}

    /**
     * 生成 SQL 前校验并对齐各行字段，保留行顺序及 NULL 值。
     * @return non-empty-list<non-empty-array<string, mixed>>
     */
    public static function normalizeBatch(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            throw new \InvalidArgumentException('Batch insert requires a non-empty array of associative rows');
        }
        $columns = null;
        $columnSet = [];
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                throw new \InvalidArgumentException('Every batch row must be a non-empty associative array');
            }
            if ($columns === null) {
                $columns = array_keys($row);
                foreach ($columns as $column) { self::quoteIdentifier($column); }
                $columnSet = array_fill_keys($columns, true);
            }
            if (count($row) !== count($columns) || array_diff_key($columnSet, $row) !== []) {
                throw new \InvalidArgumentException('All batch rows must contain exactly the same columns');
            }
            $aligned = [];
            foreach ($columns as $column) { $aligned[$column] = $row[$column]; }
            $normalized[] = $aligned;
        }
        return $normalized;
    }

    public function appendData(mixed $tableData): void
    {
        $this->batchColumns = null;
        if (!is_array($tableData)) { return; }
        $isInsert = preg_match('/\A(?:INSERT|REPLACE)\b/', $this->buffer->sql) === 1;
        if ($isInsert && isset($tableData[0]) && is_array($tableData[0])) {
            $rows = self::normalizeBatch($tableData);
            $columns = array_keys($rows[0]);
            $this->batchColumns = $columns;
            $this->buffer->sql .= ' (' . implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)) . ') ';
            $this->buffer->sql .= ' VALUES ';
            foreach ($rows as $row) {
                $this->buffer->sql .= '(';
                $this->appendPairs($row, $columns, true);
                $this->buffer->sql .= '),';
            }
            $this->buffer->sql = rtrim($this->buffer->sql, ',');
            return;
        }
        $columns = array_keys($tableData);
        if ($isInsert) {
            if ($columns !== []) {
                $this->buffer->sql .= ' (' . implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)) . ') ';
            }
            $this->buffer->sql .= ' VALUES (';
        } else {
            $this->buffer->sql .= ' SET ';
        }
        $this->appendPairs($tableData, $columns, $isInsert);
        if ($isInsert) { $this->buffer->sql .= ')'; }
    }

    private function appendPairs(mixed $tableData, mixed $tableColumns, mixed $isInsert, bool $literalColumns = false): void
    {
        foreach ($tableColumns as $column) {
            $value = $tableData[$column];
            $columnReference = self::quoteIdentifier($column);
            if (!$isInsert) {
                if (!$literalColumns) {
                    $columnReference = TableReference::column($column);
                }
                $this->buffer->sql .= $columnReference . ' = ';
            }
            if ($value instanceof Expression) {
                $this->buffer->sql .= $value->getSql() . ', ';
                $this->buffer->bindAll($value->getParameters());
                continue;
            }
            if ($value instanceof QueryBuilder && $value->isSubQuery()) {
                $this->buffer->sql .= $this->conditions->pair('', $value) . ', ';
                continue;
            }
            if (!is_array($value)) {
                $this->buffer->bind($value);
                $this->buffer->sql .= '?, ';
                continue;
            }
            $key = key($value);
            $val = $value[$key];
            switch ($key) {
                case '[I]':
                    $this->buffer->sql .= $columnReference . $val . ', ';
                    break;
                case '[F]':
                    $this->buffer->sql .= $val[0] . ', ';
                    if (!empty($val[1])) { $this->buffer->bindAll($val[1]); }
                    break;
                case '[N]':
                    $this->buffer->sql .= '!' . ($val == null ? $columnReference : $val) . ', ';
                    break;
                default:
                    throw new Exception("Wrong operation or value for column {$column} in table {$this->buffer->tableName}");
            }
        }
        $this->buffer->sql = rtrim($this->buffer->sql, ', ');
    }

    public function appendDuplicate(mixed $tableData, mixed $updateColumns): void
    {
        if (!is_array($updateColumns) || $updateColumns === []) { return; }
        $explicit = [];
        foreach ($updateColumns as $key => $value) {
            if (is_int($key)) {
                self::quoteIdentifier($value);
                $updateColumns[$value] = '';
                unset($updateColumns[$key]);
            } else {
                $explicit[$key] = $value;
            }
        }
        $columns = array_keys($updateColumns);
        if ($this->batchColumns === null) {
            foreach ($columns as $column) {
                if (!array_key_exists($column, $explicit) && (!is_array($tableData) || !array_key_exists($column, $tableData))) {
                    throw new \InvalidArgumentException('Implicit duplicate-update column must exist in the inserted row');
                }
            }
            foreach ($explicit as $column => $value) { $tableData[$column] = $value; }
            $this->buffer->sql .= ' ON DUPLICATE KEY UPDATE ';
            $this->appendPairs($tableData, $columns, false, true);
            return;
        }
        $incomingColumns = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $explicit)) { continue; }
            if (!in_array($column, $this->batchColumns, true)) {
                throw new \InvalidArgumentException('Implicit duplicate-update column must exist in every batch row');
            }
            $incomingColumns[] = $column;
        }
        $alias = null;
        if ($incomingColumns !== []) {
            if (!preg_match('/\AINSERT\b/', $this->buffer->sql)) {
                throw new \InvalidArgumentException('Incoming-row duplicate updates require INSERT, not REPLACE');
            }
            $table = trim(str_replace('`', '', (string) $this->buffer->tableName));
            $table = substr($table, strrpos('.' . $table, '.'));
            $alias = strcasecmp($table, '__mysqli_new') === 0 ? '__mysqli_new_row' : '__mysqli_new';
            $this->buffer->sql .= ' AS ' . self::quoteIdentifier($alias);
        }
        $this->buffer->sql .= ' ON DUPLICATE KEY UPDATE ';
        $separator = '';
        foreach ($columns as $column) {
            $this->buffer->sql .= $separator;
            if (array_key_exists($column, $explicit)) {
                $this->appendPairs($explicit, [$column], false, true);
            } else {
                $this->buffer->sql .= self::quoteIdentifier($column) . ' = '
                    . self::quoteIdentifier($alias) . '.' . self::quoteIdentifier($column);
            }
            $separator = ', ';
        }
    }

    private static function quoteIdentifier(mixed $name): string
    {
        return TableReference::identifier($name);
    }
}
