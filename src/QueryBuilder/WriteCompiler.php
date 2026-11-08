<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Exception\Exception;

/** @internal Compile INSERT/REPLACE values, UPDATE assignments and duplicate-key updates. */
final class WriteCompiler
{
    public function __construct(private readonly SqlBuffer $buffer, private readonly ConditionCompiler $conditions) {}

    public function appendData($tableData): void
    {
        if (!is_array($tableData)) {
            return;
        }
        $isInsert = preg_match('/^[INSERT|REPLACE]/', $this->buffer->sql);
        // 如果是二维数组则为批量插入
        if (isset($tableData[0]) && is_array($tableData[0])){
            $dataColumns = array_keys($tableData[0]);
            if ($isInsert) {
                if (isset ($dataColumns[0]))
                    $this->buffer->sql .= ' (`' . implode('`, `', $dataColumns) . '`) ';
                $this->buffer->sql .= ' VALUES ';
                foreach ($tableData as $data){
                    $this->buffer->sql .= '(';
                    $this->appendPairs($data, $dataColumns, $isInsert);
                    $this->buffer->sql .= '),';
                }
                $this->buffer->sql = rtrim($this->buffer->sql, ',');
            } else {
                $this->buffer->sql .= " SET ";
                $this->appendPairs($tableData, $dataColumns, $isInsert);
            }
        }else{
            $dataColumns = array_keys($tableData);
            if ($isInsert) {
                if (isset ($dataColumns[0]))
                    $this->buffer->sql .= ' (`' . implode('`, `', $dataColumns) . '`) ';
                $this->buffer->sql .= ' VALUES (';
            } else {
                $this->buffer->sql .= " SET ";
            }
            $this->appendPairs($tableData, $dataColumns, $isInsert);
            if ($isInsert) {
                $this->buffer->sql .= ')';
            }
        }
    }

    private function appendPairs($tableData, $tableColumns, $isInsert): void
    {
        foreach ($tableColumns as $column) {
            $value = $tableData[$column];
            if (!$isInsert) {
                if (strpos($column, '.') === false) {
                    $this->buffer->sql .= "`" . $column . "` = ";
                } else {
                    $this->buffer->sql .= str_replace('.', '.`', $column) . "` = ";
                }
            }
            if ($value instanceof QueryBuilder && $value->isSubQuery()) {
                $this->buffer->sql .= $this->conditions->pair("", $value) . ", ";
                continue;
            }
            // Simple value
            if (!is_array($value)) {
                $this->buffer->bind($value);
                $this->buffer->sql .= '?, ';
                continue;
            }
            // Function value
            $key = key($value);
            $val = $value[$key];
            switch ($key) {
                case '[I]':
                    $this->buffer->sql .= $column . $val . ", ";
                    break;
                case '[F]':
                    $this->buffer->sql .= $val[0] . ", ";
                    if (!empty($val[1])) {
                        $this->buffer->bindAll($val[1]);
                    }
                    break;
                case '[N]':
                    if ($val == null) {
                        $this->buffer->sql .= "!" . $column . ", ";
                    } else {
                        $this->buffer->sql .= "!" . $val . ", ";
                    }
                    break;
                default:
                    throw new Exception("Wrong operation or value for column {$column} in table {$this->buffer->tableName}");
            }
        }
        $this->buffer->sql = rtrim($this->buffer->sql, ', ');
    }

    public function appendDuplicate($tableData, $updateColumns): void
    {
        if (is_array($updateColumns) && !empty($updateColumns)) {
            $this->buffer->sql .= " ON DUPLICATE KEY UPDATE ";
            foreach ($updateColumns as $key => $val) {
                // skip all params without a value
                if (is_numeric($key)) {
                    $updateColumns[$val] = '';
                    unset($updateColumns[$key]);
                } else {
                    $tableData[$key] = $val;
                }
            }
            $this->appendPairs($tableData, array_keys($updateColumns), false);
        }
    }
}
