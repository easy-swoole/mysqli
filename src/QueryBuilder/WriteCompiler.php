<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Expression;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Exception\Exception;

/** @internal 处理批量选项、写入字段过滤、赋值及重复键更新。 */
final class WriteCompiler
{
    public function __construct(private readonly SqlBuffer $buffer) {}

    /** @return array{statement: 'INSERT'|'REPLACE', fields: ?array} */
    public static function batchOptions(mixed $options, ?array $selectedFields): array
    {
        if (!is_array($options)) {
            throw new \TypeError('Batch insert options must be an array');
        }
        $unknown = array_diff_key($options, ['field' => true, 'replace' => true]);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown batch insert option: ' . array_key_first($unknown));
        }
        $options += ['replace' => false];
        $fields = null;
        if (array_key_exists('field', $options)) {
            $fields = $options['field'];
            if (!is_array($fields)) {
                throw new \TypeError('Batch insert field option must be an array of column names');
            }
            foreach ($fields as $field) {
                if (!is_string($field)) {
                    throw new \TypeError('Batch insert field option must contain only strings');
                }
            }
            if ($selectedFields !== null) {
                $fields = array_filter($fields, static fn(string $field): bool => in_array($field, $selectedFields, true));
            }
        }
        if (!is_bool($options['replace'])) {
            throw new \TypeError('Batch insert replace option must be a boolean');
        }
        return ['statement' => $options['replace'] ? 'REPLACE' : 'INSERT', 'fields' => $fields];
    }

    /** @return array{array<string, string>, \Generator} */
    private static function prepareBatch(mixed $rows, ?array $fields): array
    {
        $rows = self::batchRows($rows, $fields);
        $columns = self::batchReferences($rows->current());
        return [$columns, self::alignedRows($rows, $columns)];
    }

    private static function batchRows(mixed $rows, ?array $fields): \Generator
    {
        if (!is_array($rows) || $rows === []) {
            throw new \InvalidArgumentException('Batch insert requires a non-empty array of associative rows');
        }
        $allowed = self::fieldKeys($fields);
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                throw new \InvalidArgumentException('Every batch row must be a non-empty associative array');
            }
            yield self::filterRow($row, $allowed);
        }
    }

    private static function batchReferences(array $row): array
    {
        $columns = [];
        foreach ($row as $column => $_) {
            $columns[$column] = TableReference::identifier($column);
        }
        return $columns;
    }

    private static function alignedRows(\Generator $rows, array $columns): \Generator
    {
        // prepareBatch() 已用当前首行生成列映射。
        yield $rows->current();
        for ($rows->next(); $rows->valid(); $rows->next()) {
            $row = $rows->current();
            if (count($row) !== count($columns) || array_diff_key($columns, $row) !== []) {
                throw new \InvalidArgumentException('All batch rows must contain exactly the same columns');
            }
            yield $row;
        }
    }

    public function appendUpdate(array $tableData, ?array $fields): void
    {
        $this->appendRow($tableData, false, $fields);
    }

    /** 单行和批量插入共用已写入的列映射。 */
    public function appendInsert(array $tableData, ?array $fields, bool $batch, ?array $updateColumns): void
    {
        $batch = self::isBatch($tableData, $batch);
        $columns = $batch ? $this->appendBatch($tableData, $fields) : $this->appendRow($tableData, true, $fields);
        $this->appendDuplicateAssignments($columns, $updateColumns, $batch);
    }

    private static function isBatch(array $tableData, bool $batch): bool
    {
        $first = array_key_first($tableData);
        return $batch || (is_int($first) && is_array($tableData[$first]));
    }

    /** @return array<string, string> */
    private function appendBatch(array $tableData, ?array $fields): array
    {
        [$columns, $rows] = self::prepareBatch($tableData, $fields);
        $this->buffer->sql .= ' (' . implode(', ', $columns) . ')  VALUES ';
        $separator = '';
        foreach ($rows as $row) {
            $this->buffer->sql .= $separator . '(';
            $this->appendPairs($row, $columns, true);
            $this->buffer->sql .= ')';
            $separator = ',';
        }
        return $columns;
    }

    private function appendRow(array $tableData, bool $isInsert, ?array $fields): array
    {
        $tableData = self::filterRow($tableData, self::fieldKeys($fields));
        if (!$isInsert && $tableData === []) {
            throw new \InvalidArgumentException('UPDATE requires at least one assignment');
        }
        $columns = [];
        foreach (array_keys($tableData) as $column) {
            $columns[$column] = !$isInsert && is_string($column)
                ? TableReference::column($column) : TableReference::identifier($column);
        }
        if ($isInsert) {
            if ($columns !== []) {
                $this->buffer->sql .= ' (' . implode(', ', $columns) . ') ';
            }
            $this->buffer->sql .= ' VALUES (';
        } else {
            $this->buffer->sql .= ' SET ';
        }
        $this->appendPairs($tableData, $columns, $isInsert);
        if ($isInsert) { $this->buffer->sql .= ')'; }
        return $columns;
    }

    private static function fieldKeys(?array $fields): ?array
    {
        if ($fields === null) {
            return null;
        }
        $keys = [];
        foreach ($fields as $field) {
            if (is_string($field)) {
                $keys[$field] = true;
            }
        }
        return array_filter($keys, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    private static function filterRow(array $row, ?array $allowed): array
    {
        if ($allowed === null || $row === []) {
            return $row;
        }
        $filtered = array_intersect_key($row, $allowed);
        if ($filtered === []) {
            throw new \InvalidArgumentException('No writable columns remain after field filtering');
        }
        return $filtered;
    }

    /** @param array<string, string> $columns 以绑定顺序保存列名及其 SQL 引用。 */
    private function appendPairs(array $tableData, array $columns, bool $isInsert, string $readQualifier = '', bool $batchDuplicate = false): void
    {
        $separator = '';
        foreach ($columns as $column => $columnReference) {
            $this->buffer->sql .= $separator;
            $separator = ', ';
            $value = $tableData[$column];
            if (!$isInsert) {
                $this->buffer->sql .= $columnReference . ' = ';
            }
            if ($value instanceof Expression) {
                $this->buffer->sql .= $value->getSql();
                $this->buffer->bindAll($value->getParameters());
                continue;
            }
            if ($value instanceof QueryBuilder && $value->isSubQuery()) {
                $subquery = SelectPlanner::subqueryReference($value, derivedTable: false);
                $this->buffer->sql .= '  ' . $subquery['sql'];
                $this->buffer->bindAll($subquery['params']);
                if (!$batchDuplicate) { $separator = ' , '; }
                continue;
            }
            if (!is_array($value)) {
                $this->buffer->bind($value);
                $this->buffer->sql .= '?';
                continue;
            }
            $key = key($value);
            $val = $value[$key];
            switch ($key) {
                case '[I]':
                    $this->buffer->sql .= $readQualifier . $columnReference . $val;
                    break;
                case '[F]':
                    $this->buffer->sql .= $val[0];
                    $parameters = $val[1] ?? null;
                    if ($parameters !== null) {
                        $this->buffer->bindAll($parameters);
                    }
                    break;
                case '[N]':
                    $this->buffer->sql .= '!' . ($val ?? ($readQualifier . $columnReference));
                    break;
                default:
                    throw new Exception("Wrong operation or value for column {$column} in table {$this->buffer->tableName}");
            }
        }
    }

    private function appendDuplicateAssignments(array $insertColumns, ?array $updateColumns, bool $batch): void
    {
        if ($updateColumns === null || $updateColumns === []) { return; }
        $updates = [];
        foreach ($updateColumns as $key => $value) {
            $implicit = is_int($key);
            $column = $implicit ? $value : $key;
            if (!is_string($column)) {
                throw new \InvalidArgumentException('Implicit duplicate-update columns must be strings');
            }
            $name = strtolower($column);
            if (isset($updates[$name])) {
                throw new \InvalidArgumentException("Duplicate update assignment for column {$column}");
            }
            $updates[$name] = [$column, $implicit, $value];
        }

        $columns = [];
        $values = [];
        $incoming = [];
        foreach ($updates as [$column, $implicit, $value]) {
            $columns[$column] = $insertColumns[$column] ?? TableReference::identifier($column);
            if (!$implicit) {
                $values[$column] = $value;
                continue;
            }
            if (!isset($insertColumns[$column])) {
                throw new \InvalidArgumentException('Implicit duplicate-update column must exist in the inserted row');
            }
            $incoming[] = $column;
        }

        $readQualifier = '';
        if ($incoming !== []) {
            $table = $this->buffer->baseTableName;
            $alias = $table !== null && strcasecmp($table, '__mysqli_new') === 0 ? '`__mysqli_new_row`' : '`__mysqli_new`';
            $this->buffer->sql .= ' AS ' . $alias;
            $readQualifier = TableReference::identifier($table) . '.';
            // 引用 MySQL 已计算的插入值，避免重复求值。
            foreach ($incoming as $column) {
                $values[$column] = new Expression($alias . '.' . $columns[$column]);
            }
        }
        $this->buffer->sql .= ' ON DUPLICATE KEY UPDATE ';
        $this->appendPairs($values, $columns, false, $readQualifier, $batch);
    }
}
