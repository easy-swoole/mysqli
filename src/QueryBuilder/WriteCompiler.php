<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Expression;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Exception\Exception;

/** @internal 处理批量选项、写入字段过滤、赋值及重复键更新。 */
final class WriteCompiler
{
    /** @var array<string, string>|null */
    private ?array $batchColumns = null;

    public function __construct(private readonly SqlBuffer $buffer, private readonly ConditionCompiler $conditions) {}

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

    /**
     * 保留过滤后的行，字段引用按首行的键顺序生成一次。
     * @return array{columns: non-empty-array<string, string>, rows: non-empty-list<non-empty-array<string, mixed>>}
     */
    public static function normalizeBatch(mixed $rows, ?array $fields = null): array
    {
        if (!is_array($rows) || $rows === []) {
            throw new \InvalidArgumentException('Batch insert requires a non-empty array of associative rows');
        }
        $columns = [];
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                throw new \InvalidArgumentException('Every batch row must be a non-empty associative array');
            }
            $row = self::filterRow($row, $fields);
            if ($columns === []) {
                foreach (array_keys($row) as $column) {
                    $columns[$column] = TableReference::identifier($column);
                }
            } elseif (count($row) !== count($columns) || array_diff_key($columns, $row) !== []) {
                throw new \InvalidArgumentException('All batch rows must contain exactly the same columns');
            }
            $normalized[] = $row;
        }
        return ['columns' => $columns, 'rows' => $normalized];
    }

    /** 返回过滤后的单行，供单行重复键更新读取。 */
    public function appendData(array $tableData, bool $isInsert, ?array $fields, bool $batch = false): array
    {
        $this->batchColumns = null;
        $first = array_key_first($tableData);
        if ($isInsert && ($batch || (is_int($first) && is_array($tableData[$first])))) {
            $batchData = self::normalizeBatch($tableData, $fields);
            $columns = $batchData['columns'];
            $this->batchColumns = $columns;
            $this->buffer->sql .= ' (' . implode(', ', $columns) . ')  VALUES ';
            $separator = '';
            foreach ($batchData['rows'] as $row) {
                $this->buffer->sql .= $separator . '(';
                $this->appendPairs($row, $columns, true);
                $this->buffer->sql .= ')';
                $separator = ',';
            }
            return [];
        }
        $tableData = self::filterRow($tableData, $fields);
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
        return $tableData;
    }

    private static function filterRow(array $row, ?array $fields): array
    {
        if ($fields === null || $row === []) {
            return $row;
        }
        $filtered = array_filter($row, static fn($column) => in_array($column, $fields, true), ARRAY_FILTER_USE_KEY);
        if ($filtered === []) {
            throw new \InvalidArgumentException('No writable columns remain after field filtering');
        }
        return $filtered;
    }

    /** @param array<string, string> $columns 以绑定顺序保存列名及其 SQL 引用。 */
    private function appendPairs(array $tableData, array $columns, bool $isInsert): void
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
                // 把子查询渲染器生成的尾部空格留给下一项分隔符。
                $this->buffer->sql .= substr($this->conditions->pair('', $value), 0, -1);
                $separator = ' , ';
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
                    $this->buffer->sql .= $columnReference . $val;
                    break;
                case '[F]':
                    $this->buffer->sql .= $val[0];
                    $parameters = $val[1] ?? null;
                    if ($parameters !== null) {
                        $this->buffer->bindAll($parameters);
                    }
                    break;
                case '[N]':
                    $this->buffer->sql .= '!' . ($val ?? $columnReference);
                    break;
                default:
                    throw new Exception("Wrong operation or value for column {$column} in table {$this->buffer->tableName}");
            }
        }
    }

    public function appendDuplicate(mixed $tableData, mixed $updateColumns): void
    {
        if ($updateColumns === null || $updateColumns === []) { return; }
        $explicit = [];
        $references = $this->batchColumns ?? [];
        foreach ($updateColumns as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value) || !isset($references[$value])) {
                    $reference = TableReference::identifier($value);
                    $references[$value] = $reference;
                }
                $updateColumns[$value] = '';
                unset($updateColumns[$key]);
            } else {
                $explicit[$key] = $value;
            }
        }
        $columns = [];
        foreach (array_keys($updateColumns) as $column) {
            $columns[$column] = $references[$column] ?? TableReference::identifier($column);
        }
        if ($this->batchColumns === null) {
            foreach ($columns as $column => $reference) {
                if (!array_key_exists($column, $explicit) && !array_key_exists($column, $tableData)) {
                    throw new \InvalidArgumentException('Implicit duplicate-update column must exist in the inserted row');
                }
            }
            foreach ($explicit as $column => $value) { $tableData[$column] = $value; }
            $this->buffer->sql .= ' ON DUPLICATE KEY UPDATE ';
            $this->appendPairs($tableData, $columns, false);
            return;
        }
        $incomingColumns = [];
        foreach ($columns as $column => $reference) {
            if (array_key_exists($column, $explicit)) { continue; }
            if (!isset($this->batchColumns[$column])) {
                throw new \InvalidArgumentException('Implicit duplicate-update column must exist in every batch row');
            }
            $incomingColumns[] = $column;
        }
        $alias = null;
        if ($incomingColumns !== []) {
            $table = trim(str_replace('`', '', (string) $this->buffer->tableName));
            $table = substr($table, strrpos('.' . $table, '.'));
            $alias = TableReference::identifier(strcasecmp($table, '__mysqli_new') === 0 ? '__mysqli_new_row' : '__mysqli_new');
            $this->buffer->sql .= ' AS ' . $alias;
        }
        $this->buffer->sql .= ' ON DUPLICATE KEY UPDATE ';
        $separator = '';
        foreach ($columns as $column => $reference) {
            $this->buffer->sql .= $separator;
            if (array_key_exists($column, $explicit)) {
                $this->appendPairs($explicit, [$column => $reference], false);
            } else {
                $this->buffer->sql .= $reference . ' = ' . $alias . '.' . $reference;
            }
            $separator = ', ';
        }
    }
}
