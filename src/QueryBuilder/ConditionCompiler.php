<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\ConditionValue;
use EasySwoole\Mysqli\Exception\Exception;

/** @internal Compile WHERE, HAVING, JOIN values and nested conditions. */
final class ConditionCompiler
{
    public function __construct(private readonly SqlBuffer $buffer) {}

    public function pair($operator, $value)
    {
        if (!is_object($value)) {
            $this->buffer->bind($value);
            return ' ' . $operator . ' ? ';
        }
        if($value instanceof QueryBuilder){
            $subQuery = $value->getSubQuery();
            $this->buffer->bindAll($subQuery['params']);
            return " " . $operator . " (" . $subQuery['query'] . ") " . $subQuery['alias'];
        }else{
            $type = get_class($value);
            throw new Exception("value for column {$this->buffer->column} in table {$this->buffer->tableName} cannot be {$type}");
        }
    }

    /** ON expressions are supplied by the caller; keep their field text unchanged. */
    public function appendJoinValue($operator, $value): void
    {
        $this->appendValue($operator, $value);
    }

    public function append($operator, $conditions): void
    {
        if (empty($conditions)) {
            return;
        }
        //Prepare the where portion of the query
        $this->buffer->sql .= ' ' . $operator;
        foreach ($conditions as $cond) {
            if (isset($cond['group'])) {
                $this->buffer->sql .= ' ' . $cond['concat'] . ' (';
                $this->append('', $cond['group']);
                $this->buffer->sql .= ')';
                continue;
            }
            if (isset($cond['exists'])) {
                $this->buffer->sql .= ' ' . $cond['concat'] . ' ' . $cond['operator'] . $this->pair('', $cond['exists']);
                continue;
            }
            list ($concat, $varName, $operator, $val) = $cond;

            $this->buffer->column = $varName;

            if (strpos($varName, '.') !== false && $val !== ConditionValue::Raw && strpos($varName, '(') === false && strpos($varName, '?') === false){
                // Raw 条件为完整 SQL 表达式，不对其引用字段名。
                $varNameArray = explode('.', $varName);
                $varName = "`{$varNameArray[0]}`.`{$varNameArray[1]}`";
            }else{
                // 不是字符串条件，也没有包含()函数调用
                if ($val !== ConditionValue::Raw && strpos($varName, '(') === false && strpos($varName, '?') === false){
                    $varName = "`{$varName}`";
                }
            }

            $this->buffer->sql .= " " . $concat . " " . $varName;
            if ($val === ConditionValue::Raw) {
                continue;
            }
            $this->appendValue($operator, $val, $varName);
        }
    }

    /** Shared operator handling keeps WHERE/HAVING and ON binding semantics consistent. */
    private function appendValue($operator, $value, ?string $conditionField = null): void
    {
        if ($value === ConditionValue::Raw) {
            return;
        }
        switch (strtolower($operator)) {
            case 'not in':
            case 'in':
                $comparison = ' ' . $operator . ' (';
                if (is_object($value)) {
                    $comparison .= $this->pair('', $value);
                } else {
                    if ($conditionField !== null && !is_array($value)) {
                        $type = gettype($value);
                        throw new Exception("value for column {$this->buffer->column} in table {$this->buffer->tableName} must be array or object,{$type} given}");
                    }
                    foreach ($value as $item) {
                        $comparison .= ' ?,';
                        $this->buffer->bind($item);
                    }
                }
                $this->buffer->sql .= rtrim($comparison, ',') . ' ) ';
                break;
            case 'not between':
            case 'between':
                $this->buffer->sql .= " $operator ? AND ? ";
                $this->buffer->bindAll($value);
                break;
            case 'not exists':
            case 'exists':
                $this->buffer->sql .= $operator . $this->pair('', $value);
                break;
            default:
                if ($conditionField !== null && in_array($operator, ['=', '<=>', '<>', '!=', '>', '<', '>=', '<='])
                    && is_array($value) && strpos($conditionField, '?') === false) {
                    throw new Exception("value for column {$this->buffer->column} in table {$this->buffer->tableName} can not be array");
                }
                if (is_array($value)) {
                    $this->buffer->bindAll($value);
                } elseif ($value === null) {
                    $this->buffer->sql .= ' ' . $operator . ' NULL';
                } else {
                    $this->buffer->sql .= $this->pair($operator, $value);
                }
        }
    }
}
