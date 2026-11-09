<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\ConditionValue;
use EasySwoole\Mysqli\Expression;
use EasySwoole\Mysqli\Exception\Exception;

/**
 * @internal Compile WHERE, HAVING, JOIN values and nested conditions.
 * @phpstan-type ConditionNode array{0: string, 1: mixed, 2: string, 3: mixed}|array{group: array, concat: string}|array{exists: QueryBuilder, operator: string, concat: string}|array{column: array{string, string, string}, concat: string}|array{expression: Expression, concat: string}
 */
final class ConditionCompiler
{
    public function __construct(private readonly SqlBuffer $buffer) {}

    public function pair(mixed $operator, mixed $value): string
    {
        if ($value instanceof Expression) {
            $this->buffer->bindAll($value->getParameters());
            return ' ' . $operator . ' ' . $value->getSql() . ' ';
        }
        if (!is_object($value)) {
            $this->buffer->bind($value);
            return ' ' . $operator . ' ? ';
        }
        if ($value instanceof QueryBuilder) {
            $subQuery = $value->getSubQuery();
            $this->buffer->bindAll($subQuery['params']);
            return " " . $operator . " (" . $subQuery['query'] . ") " . $subQuery['alias'];
        }
        $type = get_class($value);
        throw new Exception("value for column {$this->buffer->column} in table {$this->buffer->tableName} cannot be {$type}");
    }

    /**
     * ON tuples keep their legacy field text; structured column nodes quote both identifiers.
     * @param array<array-key, ConditionNode> $conditions
     */
    public function appendOn(array $conditions): void
    {
        $this->appendList($conditions, true);
    }

    /** @param array<array-key, ConditionNode>|null $conditions */
    public function append(mixed $operator, mixed $conditions): void
    {
        if (empty($conditions)) {
            return;
        }
        $this->buffer->sql .= ' ' . $operator;
        $this->appendList($conditions, false);
    }

    /**
     * The same condition tree can be compiled in WHERE, HAVING or a JOIN ON scope.
     * @param array<array-key, ConditionNode> $conditions
     */
    private function appendList(array $conditions, bool $on): void
    {
        foreach ($conditions as $cond) {
            if (isset($cond['group'])) {
                $this->buffer->sql .= ' ' . $cond['concat'] . ' ( ';
                $this->appendList($cond['group'], $on);
                $this->buffer->sql .= ')';
                continue;
            }
            if (isset($cond['exists'])) {
                $this->buffer->sql .= ' ' . $cond['concat'] . ' ' . $cond['operator'] . $this->pair('', $cond['exists']);
                continue;
            }
            if (isset($cond['column'])) {
                [$first, $operator, $second] = $cond['column'];
                $this->buffer->sql .= ' ' . $cond['concat'] . ' ' . TableReference::column($first)
                    . ' ' . $operator . ' ' . TableReference::column($second);
                continue;
            }
            if (isset($cond['expression'])) {
                $expression = $cond['expression'];
                $this->buffer->sql .= ' ' . $cond['concat'] . ' (' . $expression->getSql() . ')';
                $this->buffer->bindAll($expression->getParameters());
                continue;
            }
            [$concat, $field, $operator, $value] = $cond;
            $this->buffer->column = $field instanceof Expression ? $field->getSql() : $field;
            if ($field instanceof Expression) {
                $this->buffer->bindAll($field->getParameters());
                $field = $field->getSql();
            } elseif (!$on && $value !== ConditionValue::Raw
                && strpos($field, '(') === false && strpos($field, '?') === false) {
                $field = TableReference::column($field);
            }
            $this->buffer->sql .= ' ' . $concat . ' ' . $field;
            $this->appendValue($operator, $value, $on ? null : $field);
        }
    }

    /** Shared operator handling keeps WHERE/HAVING and ON binding semantics consistent. */
    private function appendValue(mixed $operator, mixed $value, ?string $conditionField = null): void
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
                if ($conditionField !== null && ComparisonOperator::tryFrom($operator) !== null
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
