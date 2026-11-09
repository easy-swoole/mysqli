<?php

namespace EasySwoole\Mysqli;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\QueryBuilder\{
    CompiledQuery,
    ComparisonOperator,
    ConditionConjunction,
    ExpressionFactory,
    JoinType,
    LockWaitPolicy,
    PendingQuery,
    QueryCompiler,
    SelectCompiler,
    SelectPlanner,
    SortDirection,
    SqlBuffer,
    SqlFormatter,
    SqlList,
    TableLockMode,
    TableReference,
    WriteCompiler
};

/**
 * Public fluent API; planning and SQL compilation are delegated to internal collaborators.
 * @phpstan-import-type State from PendingQuery
 */
class QueryBuilder
{
    private mixed $prefix = '';
    private string $tableLockMethod = 'READ';
    private bool $isSubQuery = false;
    private ?string $subQueryAlias = '';
    private bool $lastUnionOperandNeedsParentheses = true;

    /** @var State Copy-on-write state; clones never share a mutable state object. */
    private array $pending = PendingQuery::DEFAULTS;
    private ?CompiledQuery $compiled = null;
    // Debug SQL becomes visible before a virtual reset() publishes the executable snapshot.
    private mixed $lastRenderedQuery = null;

    const TS_OP_START = 1;
    const TS_OP_COMMIT = 2;
    const TS_OP_ROLLBACK = 3;
    const TS_OP_LOCK_TABLE = 4;
    const TS_OP_UNLOCK_TABLE = 5;
    const TS_OP_LOCK_IN_SHARE = 6;
    const TS_OP_LOCK_FOR_UPDATE = 7;

    /** 创建普通查询或带别名的子查询构造器。 */
    public function __construct(bool $isSubQuery = false, string|null $subQueryAlias = null)
    {
        if ($isSubQuery) {
            $this->isSubQuery = true;
            $this->subQueryAlias = $subQueryAlias;
        }
    }

    /** 设置查询的返回数量或偏移及数量。 */
    public function limit(int $one, int|null $two = null): QueryBuilder
    {
        if ($two !== null) {
            $this->pending['limit'] = [$one, $two];
        } else {
            $this->pending['limit'] = $one;
        }
        return $this;
    }

    /** 对所选列的组合去重，仅影响下一次构建的查询。 */
    public function distinct(bool $isDistinct = true): QueryBuilder
    {
        $removeOption = $isDistinct ? 'ALL' : 'DISTINCT';
        $this->pending['queryOptions'] = array_values(array_filter(
            $this->pending['queryOptions'],
            static fn($option) => $option !== $removeOption
        ));
        if ($isDistinct) {
            $this->setQueryOption('DISTINCT');
        }
        return $this;
    }

    /** 为下一次 SELECT 的主表指定强制使用的索引；重复调用覆盖之前的设置。 传入空数组可取消设置，索引名按标识符引用，不接受 SQL 片段。 */
    public function forceIndex(string|array $indexes): QueryBuilder
    {
        $this->pending['forceIndexes'] = TableReference::normalizeIndexes($indexes);
        if ($this->pending['forceIndexes'] !== []) {
            $this->pending['useIndexes'] = [];
        }
        return $this;
    }

    /** 建议下一次 SELECT 主表使用的索引；与 FORCE INDEX 互斥。 */
    public function useIndex(string|array $indexes): QueryBuilder
    {
        $this->pending['useIndexes'] = TableReference::normalizeIndexes($indexes);
        if ($this->pending['useIndexes'] !== []) {
            $this->pending['forceIndexes'] = [];
        }
        return $this;
    }

    /** 排除下一次 SELECT 主表的索引。 */
    public function ignoreIndex(string|array $indexes): QueryBuilder
    {
        $this->pending['ignoreIndexes'] = TableReference::normalizeIndexes($indexes);
        return $this;
    }

    /** 设置查询字段或写入时允许的字段。 */
    public function fields(mixed $fields): QueryBuilder
    {
        if (!is_array($fields)) {
            $fields = [$fields];
        }
        $this->pending['fields'] = $fields;
        return $this;
    }

    /** Add a parameterized SELECT expression; the first call replaces the implicit default *. */
    public function selectRaw(string $sql, array $parameters = []): QueryBuilder
    {
        $expression = new Expression($sql, $parameters);
        $fields = $this->pending['fields'] === '*' ? [] : (is_array($this->pending['fields']) ? $this->pending['fields'] : [$this->pending['fields']]);
        $fields[] = $expression;
        $this->pending['fields'] = $fields;
        return $this;
    }

    public static function rawExpression(string $sql, array $parameters = []): Expression
    {
        return new Expression($sql, $parameters);
    }

    public function whereRaw(string $sql, array $parameters = [], ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addRawCondition($this->pending['whereConditions'], $sql, $parameters, $cond);
    }

    public function havingRaw(string $sql, array $parameters = [], ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addRawCondition($this->pending['havingConditions'], $sql, $parameters, $cond);
    }

    private function addRawCondition(array &$conditions, string $sql, array $parameters, ConditionConjunction|string $cond): QueryBuilder
    {
        $concat = $this->conditionConjunction($cond);
        $expression = new Expression($sql, $parameters);
        $conditions[] = ['expression' => $expression, 'concat' => $conditions === [] ? '' : $concat];
        return $this;
    }

    private function conditionConjunction(ConditionConjunction|string $cond, bool $strict = true): string
    {
        if ($cond instanceof ConditionConjunction) { return $cond->value; }
        // Legacy WHERE/HAVING/ON strings retain their spelling and extensible SQL syntax.
        if (!$strict) { return $cond; }
        $conjunction = ConditionConjunction::tryFrom(strtoupper(trim($cond)));
        if ($conjunction === null) {
            throw new \InvalidArgumentException('Condition conjunction must be AND or OR');
        }
        return $conjunction->value;
    }

    private function comparisonOperator(ComparisonOperator|string $operator, bool $strict = false): string
    {
        if ($operator instanceof ComparisonOperator) { return $operator->value; }
        // Ordinary predicates also accept LIKE, IN, BETWEEN and other existing SQL operators.
        if (!$strict) { return $operator; }
        $comparison = ComparisonOperator::tryFrom(strtoupper(trim($operator)));
        if ($comparison === null) {
            throw new \InvalidArgumentException('Unsupported column comparison operator');
        }
        return $comparison->value;
    }

    private function lockWaitOption(LockWaitPolicy|string $option): string
    {
        if ($option instanceof LockWaitPolicy) { return $option->value; }
        // Preserve legacy SQL tails (such as OF table_name); only known policies are normalized.
        return LockWaitPolicy::tryFrom(strtoupper(trim($option)))?->value ?? $option;
    }

    public function whereColumn(string $first, string $second, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addColumnCondition($this->pending['whereConditions'], $first, $second, $operator, $cond);
    }

    public function orWhereColumn(string $first, string $second, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->whereColumn($first, $second, $operator, 'OR');
    }

    public function havingColumn(string $first, string $second, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addColumnCondition($this->pending['havingConditions'], $first, $second, $operator, $cond);
    }

    public function orHavingColumn(string $first, string $second, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->havingColumn($first, $second, $operator, 'OR');
    }

    private function addColumnCondition(array &$conditions, string $first, string $second, ComparisonOperator|string $operator, ConditionConjunction|string $cond, bool $on = false): QueryBuilder
    {
        $operator = $this->comparisonOperator($operator, true);
        TableReference::column($first);
        TableReference::column($second);
        $concat = $this->conditionConjunction($cond);
        $conditions[] = ['column' => [$first, $operator, $second], 'concat' => !$on && $conditions === [] ? '' : $concat];
        return $this;
    }

    /** 以数组形式获取当前字段配置。 */
    public function getField(): array
    {
        if (!is_array($this->pending['fields'])) {
            $field = [$this->pending['fields']];
        } else {
            $field = $this->pending['fields'];
        }
        return $field;
    }

    /** Where条件 */
    public function where(mixed $whereProp, mixed $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        $operator = $this->comparisonOperator($operator);
        $cond = $this->conditionConjunction($cond, false);
        if (count($this->pending['whereConditions']) == 0) {
            $cond = '';
        }
        $this->pending['whereConditions'][] = [$cond, $whereProp, $operator, $whereValue];
        return $this;
    }

    /** WhereOr条件 */
    public function orWhere(mixed $whereProp, mixed $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->where($whereProp, $whereValue, $operator, 'OR');
    }

    /** 集合条件；空数组生成恒假条件。 */
    public function whereIn(string $column, array|QueryBuilder $values, ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        if ($values === []) {
            return $this->where('0 = 1', ConditionValue::Raw, '=', $cond);
        }
        $this->validateConditionSubquery($values);
        return $this->where($column, $values, 'IN', $cond);
    }

    /** 排除集合；空数组生成恒真条件。 */
    public function whereNotIn(string $column, array|QueryBuilder $values, ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        if ($values === []) {
            return $this->where('1 = 1', ConditionValue::Raw, '=', $cond);
        }
        $this->validateConditionSubquery($values);
        return $this->where($column, $values, 'NOT IN', $cond);
    }

    public function whereNull(string $column, ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->where($column, null, 'IS', $cond);
    }

    public function whereNotNull(string $column, ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->where($column, null, 'IS NOT', $cond);
    }

    public function whereBetween(string $column, array $bounds, ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        if (count($bounds) !== 2) {
            throw new \InvalidArgumentException('BETWEEN requires exactly two bounds');
        }
        return $this->where($column, array_values($bounds), 'BETWEEN', $cond);
    }

    /** pattern 由调用方提供，% 和 _ 保持 SQL 通配符含义。 */
    public function whereLike(string $column, string $pattern, ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->where(TableReference::column($column) . ' LIKE ?', [$pattern], '=', $cond);
    }

    public function whereExists(QueryBuilder $subquery, ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        $this->validateConditionSubquery($subquery);
        return $this->addExistsCondition($subquery, 'EXISTS', $cond);
    }

    public function whereNotExists(QueryBuilder $subquery, ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        $this->validateConditionSubquery($subquery);
        return $this->addExistsCondition($subquery, 'NOT EXISTS', $cond);
    }

    private function addExistsCondition(QueryBuilder $subquery, string $operator, ConditionConjunction|string $cond): QueryBuilder
    {
        if ($subquery->getSubQuery()['alias']) {
            throw new \InvalidArgumentException('EXISTS subqueries must not have an alias');
        }
        $cond = $this->conditionConjunction($cond, false);
        $this->pending['whereConditions'][] = ['exists' => $subquery, 'operator' => $operator, 'concat' => $this->pending['whereConditions'] === [] ? '' : $cond];
        return $this;
    }

    private function validateConditionSubquery(array|QueryBuilder $value): void
    {
        if ($value instanceof QueryBuilder && (!$value->isSubQuery() || $value->getLastPrepareQuery() === null)) {
            throw new \InvalidArgumentException('Use a built QueryBuilder::subQuery() for condition subqueries');
        }
    }

    /** 闭包仅收集 WHERE 条件，支持嵌套；空条件组会被拒绝。 */
    public function whereGroup(callable $callback): QueryBuilder
    {
        return $this->addWhereGroup($callback, 'AND');
    }

    public function orWhereGroup(callable $callback): QueryBuilder
    {
        return $this->addWhereGroup($callback, 'OR');
    }

    private function addWhereGroup(callable $callback, string $concat): QueryBuilder
    {
        return $this->addConditionGroup($this->pending['whereConditions'], $callback, $concat, 'whereConditions', 'WHERE');
    }

    public function havingGroup(callable $callback): QueryBuilder
    {
        return $this->addConditionGroup($this->pending['havingConditions'], $callback, 'AND', 'havingConditions', 'HAVING');
    }

    public function orHavingGroup(callable $callback): QueryBuilder
    {
        return $this->addConditionGroup($this->pending['havingConditions'], $callback, 'OR', 'havingConditions', 'HAVING');
    }

    public function joinWhereGroup(string $table, callable $callback): QueryBuilder
    {
        $key = $this->prefix . $table;
        $conditions = $this->pending['joinConditions'][$key] ?? [];
        $this->addConditionGroup($conditions, $callback, 'AND', 'whereConditions', 'ON', true);
        $this->pending['joinConditions'][$key] = $conditions;
        return $this;
    }

    public function joinOrWhereGroup(string $table, callable $callback): QueryBuilder
    {
        $key = $this->prefix . $table;
        $conditions = $this->pending['joinConditions'][$key] ?? [];
        $this->addConditionGroup($conditions, $callback, 'OR', 'whereConditions', 'ON', true);
        $this->pending['joinConditions'][$key] = $conditions;
        return $this;
    }

    private function addConditionGroup(array &$conditions, callable $callback, string $concat, string $source, string $label, bool $on = false): QueryBuilder
    {
        $group = new static();
        $group->setPrefix($this->prefix);
        $callback($group);
        if ($group->pending[$source] === []) {
            throw new \InvalidArgumentException($label . ' groups must contain at least one condition');
        }
        $conditions[] = ['group' => $group->pending[$source], 'concat' => !$on && $conditions === [] ? '' : $concat];
        return $this;
    }

    public function orderByRaw(string $sql, array $parameters = []): QueryBuilder
    {
        $expression = new Expression($sql, $parameters);
        $key = 'raw:' . serialize([$sql, $expression->getParameters()]);
        $this->pending['ordering'][$key] = ['field' => $expression, 'direction' => '', 'params' => []];
        return $this;
    }

    public function orderByAsc(string $column): QueryBuilder
    {
        return $this->orderBy($column, SortDirection::Asc);
    }

    public function orderByDesc(string $column): QueryBuilder
    {
        return $this->orderBy($column, SortDirection::Desc);
    }

    /** OrderBy条件 */
    public function orderBy(mixed $orderByField, SortDirection|string $orderbyDirection = "DESC", mixed $customFieldsOrRegExp = null): QueryBuilder
    {
        [$key, $order] = SelectCompiler::order($orderByField, $orderbyDirection, $customFieldsOrRegExp, $this->prefix, $this->pending['tableName']);
        $this->pending['ordering'][$key] = $order;
        return $this;
    }

    /** 清除待构建查询的全部排序，保留其他条件。 */
    public function reorder(): QueryBuilder
    {
        $this->pending['ordering'] = [];
        return $this;
    }

    /** 创建按唯一键递增分页的独立副本，不修改原构造器。 */
    public function forPageAfterId(string $column, mixed $lastId, int $size): QueryBuilder
    {
        $plan = SelectPlanner::keysetInput($this->pending, $column, $lastId, $size);
        $quoted = $plan['quotedColumn'];
        $page = clone $this;
        $page->pending['whereConditions'] = SelectPlanner::keysetWhere($page->pending['whereConditions']);
        if ($lastId !== null) { $page->where($quoted, $lastId, '>'); }
        return $page->reorder()->orderByRaw($quoted . ' ASC')->limit($size);
    }

    /** GroupBy条件 */
    public function groupBy(mixed $groupByField): QueryBuilder
    {
        $groupByField = preg_replace("/[^-a-z0-9\.\(\),_\* <>=!%`\']+/i", '', $groupByField);
        $this->pending['groups'][] = $groupByField;
        return $this;
    }

    /** Having条件 */
    public function having(mixed $havingProp, mixed $havingValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        $operator = $this->comparisonOperator($operator);
        $cond = $this->conditionConjunction($cond, false);
        if (is_array($havingValue) && ($key = key($havingValue)) != "0") {
            $operator = $this->comparisonOperator($key);
            $havingValue = $havingValue[$key];
        }
        if (count($this->pending['havingConditions']) == 0) {
            $cond = '';
        }
        $this->pending['havingConditions'][] = [$cond, $havingProp, $operator, $havingValue];
        return $this;
    }

    /** OrHaving条件 */
    public function orHaving(mixed $havingProp, mixed $havingValue = ConditionValue::Raw, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->having($havingProp, $havingValue, $operator, 'OR');
    }

    /** Join查询 */
    public function join(mixed $joinTable, mixed $joinCondition, JoinType|string $joinType = ''): QueryBuilder
    {
        $joinType = $joinType instanceof JoinType ? $joinType->value : strtoupper(trim($joinType));
        if (JoinType::tryFrom($joinType) === null && $joinType !== 'OUTER') {
            throw new Exception('Wrong JOIN type: ' . $joinType);
        }
        if (!is_object($joinTable)) {
            $joinTable = $this->prefix . $joinTable;
        }
        $conditionKey = null;
        if ($joinTable instanceof QueryBuilder) {
            $alias = $joinTable->getSubQuery()['alias'] ?? null;
            if ($alias !== null && $alias !== '') { $conditionKey = $this->prefix . $alias; }
        }
        $this->pending['joins'][] = [$joinType, $joinTable, $joinCondition, $conditionKey];
        return $this;
    }

    /** 为关联表添加 AND 条件。 Join xx where xx */
    public function joinWhere(mixed $whereJoin, mixed $whereProp, mixed $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        $operator = $this->comparisonOperator($operator);
        $cond = $this->conditionConjunction($cond, false);
        $this->pending['joinConditions'][$this->prefix . $whereJoin][] = Array($cond, $whereProp, $operator, $whereValue);
        return $this;
    }

    /** 为关联表添加 OR 条件。 Join xx or where xx */
    public function joinOrWhere(mixed $whereJoin, mixed $whereProp, mixed $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->joinWhere($whereJoin, $whereProp, $whereValue, $operator, 'OR');
    }

    public function joinOn(string $table, string $first, string $second, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        $key = $this->prefix . $table;
        $conditions = $this->pending['joinConditions'][$key] ?? [];
        $this->addColumnCondition($conditions, $first, $second, $operator, $cond, true);
        $this->pending['joinConditions'][$key] = $conditions;
        return $this;
    }

    public function joinOrOn(string $table, string $first, string $second, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->joinOn($table, $first, $second, $operator, 'OR');
    }

    /** 构建UNION语句 */
    public function union(mixed $cond, mixed $isUnionAll = false): QueryBuilder
    {
        if ($cond instanceof QueryBuilder) {
            $this->pending['unionBranches'][] = [$cond->getLastPrepareQuery(), $cond->getLastBindParams(), $isUnionAll,
                $cond->lastUnionOperandNeedsParentheses];
        } elseif (is_string($cond)) {
            // Raw SQL may already contain local ordering, pagination or another UNION.
            $this->pending['unionBranches'][] = [$cond, [], $isUnionAll, true];
        }
        return $this;
    }

    /** LockInShareModel锁定(InnoDb) */
    public function lockInShareMode(mixed $isLock = true): QueryBuilder
    {
        if ($isLock) {
            $this->setQueryOption(['LOCK IN SHARE MODE']);
        } else {
            $this->pending['lockInShareMode'] = false;
        }
        return $this;
    }

    /** SELECT FOR UPDATE锁定(InnoDb) */
    public function selectForUpdate(mixed $isLock = true, LockWaitPolicy|string|null $option = null): QueryBuilder
    {
        if ($isLock) {
            $hasOption = $option instanceof LockWaitPolicy || (bool) $option;
            $lockOption = $hasOption ? $this->lockWaitOption($option) : null;
            $this->setQueryOption(['FOR UPDATE']);
            if ($hasOption) {
                $this->pending['selectLockOption'] = $lockOption;
            }
        } else {
            $this->pending['forUpdate'] = false;
            $this->pending['selectLockOption'] = false;
        }
        return $this;
    }

    /** selectForUpdate() 的简写，保留旧接口。 */
    public function forUpdate(bool $isLock = true): QueryBuilder
    {
        return $this->selectForUpdate($isLock);
    }

    /** 开启 FOR UPDATE 并跳过已锁定的行。 */
    public function skipLocked(): QueryBuilder
    {
        return $this->selectForUpdate(true, LockWaitPolicy::SkipLocked);
    }

    /** 开启 FOR UPDATE，遇到锁立即报错。 */
    public function nowait(): QueryBuilder
    {
        return $this->selectForUpdate(true, LockWaitPolicy::Nowait);
    }

    /** 锁表模式(读/写) */
    public function setLockTableMode(TableLockMode|string $method): QueryBuilder
    {
        $mode = $method instanceof TableLockMode ? $method : TableLockMode::tryFrom(strtoupper($method));
        if ($mode === null) {
            throw new Exception("Bad lock type: Can be either READ or WRITE for table {$this->pending['tableName']}");
        }
        $this->tableLockMethod = $mode->value;
        return $this;
    }

    /** 获得表锁 */
    public function lockTable(mixed $table): QueryBuilder
    {
        $this->pending['transactionOp'] = self::TS_OP_LOCK_TABLE;
        $this->pending['sql'] = "LOCK TABLES";
        // Is the table an array?
        if (gettype($table) == "array") {
            // Loop trough it and attach it to the query
            foreach ($table as $key => $value) {
                if (gettype($value) == "string") {
                    if ($key > 0) {
                        $this->pending['sql'] .= ",";
                    }
                    $this->pending['sql'] .= " " . $this->prefix . $value . " " . $this->tableLockMethod;
                    $this->lastRenderedQuery = $this->pending['sql'];
                }
            }
        } else {
            // Build the table prefix
            $table = $this->prefix . $table;
            // Build the query
            $this->pending['sql'] = "LOCK TABLES " . $table . " " . $this->tableLockMethod;
            $this->lastRenderedQuery = $this->pending['sql'];
        }
        $this->pending['isCompiled'] = true;
        $this->reset();
        return $this;
    }

    /** 释放表锁 */
    public function unlockTable(): QueryBuilder
    {
        $this->pending['transactionOp'] = self::TS_OP_UNLOCK_TABLE;
        $this->pending['sql'] = "UNLOCK TABLES";
        $this->lastRenderedQuery = $this->pending['sql'];
        $this->pending['isCompiled'] = true;
        $this->reset();
        return $this;
    }

    /** 设置查询条件 */
    public function setQueryOption(mixed $options): QueryBuilder
    {
        $allowedOptions = ['ALL', 'DISTINCT', 'DISTINCTROW', 'HIGH_PRIORITY', 'STRAIGHT_JOIN', 'SQL_SMALL_RESULT',
            'SQL_BIG_RESULT', 'SQL_BUFFER_RESULT', 'SQL_CACHE', 'SQL_NO_CACHE', 'SQL_CALC_FOUND_ROWS',
            'LOW_PRIORITY', 'IGNORE', 'QUICK', 'FOR UPDATE', 'LOCK IN SHARE MODE'];
        if (!is_array($options)) {
            $options = [$options];
        }
        $normalized = [];
        foreach ($options as $option) {
            $option = strtoupper($option);
            if (!in_array($option, $allowedOptions)) {
                throw new Exception('Wrong query option: ' . $option . " for table {$this->pending['tableName']}");
            }
            $normalized[] = $option;
        }
        foreach ($normalized as $option) {
            if ($option == 'FOR UPDATE') {
                $this->pending['forUpdate'] = true;
            } elseif ($option == 'LOCK IN SHARE MODE') {
                $this->pending['lockInShareMode'] = true;
            } else {
                if (!in_array($option,$this->pending['queryOptions'])){
                    $this->pending['queryOptions'][] = $option;
                }
            }
        }
        return $this;
    }

    /** 设置排他锁查询的等待选项。 */
    public function setSelectLockOption(LockWaitPolicy|string $option): QueryBuilder
    {
        $this->pending['selectLockOption'] = $this->lockWaitOption($option);
        return $this;
    }

    /** 设置表前缀 */
    public function setPrefix(mixed $prefix = ''): QueryBuilder
    {
        $this->prefix = $prefix;
        return $this;
    }

    /** 统计结果行数 */
    public function withTotalCount(): QueryBuilder
    {
        $this->setQueryOption('SQL_CALC_FOUND_ROWS');
        return $this;
    }

    //---------- 查询方法 ---------- //

    /** 保存带参数的 FROM 子查询快照；使用 get(null) 或 getFromSub() 完成查询。 */
    public function fromSub(QueryBuilder $subquery, string $alias): QueryBuilder
    {
        $this->pending['fromSource'] = SelectPlanner::fromSub(
            $subquery->getLastPrepareQuery(), $subquery->getLastBindParams(), $alias
        );
        return $this;
    }

    public function getFromSub(QueryBuilder $subquery, string $alias, mixed $numRows = null, mixed $columns = null): QueryBuilder
    {
        return $this->fromSub($subquery, $alias)->get(null, $numRows, $columns);
    }

    /** SELECT查询 */
    public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
    {
        if ($columns == null) { $columns = $this->pending['fields']; }
        $plan = SelectPlanner::selectHead(
            $tableName, $columns, $this->pending['queryOptions'], $this->prefix,
            $this->pending['fromSource'], ['FORCE' => $this->pending['forceIndexes'],
                'USE' => $this->pending['useIndexes'], 'IGNORE' => $this->pending['ignoreIndexes']],
        );
        $this->pending['isCompiled'] = false;
        $this->pending['parameters'] = $plan->parameters;
        $this->pending['tableName'] = $plan->tableName;
        $this->pending['sql'] = $plan->sql;
        if ($numRows === null) { $numRows = $this->pending['limit']; }
        $this->compilePending($numRows);
        $this->lastUnionOperandNeedsParentheses = $this->pending['ordering'] !== [] || $numRows !== null
            || $this->pending['unionBranches'] !== [] || $this->pending['forUpdate'] || $this->pending['lockInShareMode'];
        $this->reset();
        return $this;
    }

    /** 统计筛选结果的行数或分组数，忽略整体排序、分页和锁。 */
    public function count(mixed $tableName, string $alias = 'aggregate'): QueryBuilder
    {
        // Validate before cloning; apply the plan after any subclass __clone() has run.
        TableReference::identifier($alias);
        $source = clone $this;
        $plan = SelectPlanner::countInput($source->pending, $alias);
        $source->pending = $plan['state'];
        $source->get($tableName);
        $sql = SelectPlanner::countSql($source->getLastPrepareQuery(), $plan['quotedAlias']);
        $this->pending['queryOptions'] = [];
        $this->pending['transactionOp'] = null;
        return $this->raw($sql, $source->getLastBindParams());
    }

    /** 构建聚合 SELECT；UNION 整体聚合应先使用 fromSub()。 */
    public function aggregate(mixed $tableName, string $function, string|Expression $column, string $alias = 'aggregate'): QueryBuilder
    {
        $expression = SelectPlanner::aggregateExpression(
            $function, $column, $alias, $this->pending['unionBranches'] !== []
        );
        $fields = $this->pending['fields'] === '*' ? [] : $this->getField();
        $fields[] = $expression;
        return $this->get($tableName, null, $fields);
    }

    public function sum(mixed $tableName, string|Expression $column, string $alias = 'aggregate'): QueryBuilder
    { return $this->aggregate($tableName, 'SUM', $column, $alias); }

    public function avg(mixed $tableName, string|Expression $column, string $alias = 'aggregate'): QueryBuilder
    { return $this->aggregate($tableName, 'AVG', $column, $alias); }

    public function min(mixed $tableName, string|Expression $column, string $alias = 'aggregate'): QueryBuilder
    { return $this->aggregate($tableName, 'MIN', $column, $alias); }

    public function max(mixed $tableName, string|Expression $column, string $alias = 'aggregate'): QueryBuilder
    { return $this->aggregate($tableName, 'MAX', $column, $alias); }

    /** SELECT LIMIT1查询 */
    public function getOne(mixed $tableName, mixed $columns = '*'): QueryBuilder
    {
        if ($columns === '*'){
            $columns = $this->pending['fields'];
        }
        return $this->get($tableName, 1, $columns);
    }

    /** SELECT 获取某一列的数据 */
    public function getColumn(mixed $tableName, mixed $columns = null, mixed $numRows = null): ?QueryBuilder
    {
        if (!is_string($columns) && !($columns instanceof Expression)) {
            $columns = $this->pending['fields'];
            if (is_array($columns) && count($columns) > 0) {
                $first = reset($columns);
                $columns = $first instanceof Expression ? $first : SqlList::first($first);
            }
        }
        return $this->get($tableName, $numRows, $columns);
    }

    /** SELECT 获取某一列第一行的数据 */
    public function getScalar(mixed $tableName, mixed $columns = null): ?QueryBuilder
    {
        return $this->getColumn($tableName, $columns, 1);
    }

    /** 插入数据 */
    public function insert(mixed $tableName, mixed $insertData): QueryBuilder
    {
        if (is_array($this->pending['fields'])) {
            foreach ($insertData as $key => $val) {
                if (!in_array($key, $this->pending['fields'])) {
                    unset($insertData[$key]);
                }
            }
        }
        $this->buildInsert($tableName, $insertData, 'INSERT');
        $this->reset();
        return $this;
    }
    //多行插入为INSERT INTO ... VALUES (...) , (...)
    /** 构造使用多个 VALUES 分组的批量插入语句。 */
    public function insertAll(mixed $tableName, mixed $insertData, mixed $option = []): QueryBuilder
    {
        if (!is_array($insertData) || $insertData === []) {
            throw new \InvalidArgumentException('Batch insert requires a non-empty array of associative rows');
        }
        foreach ($insertData as $row) {
            if (!is_array($row) || $row === []) {
                throw new \InvalidArgumentException('Every batch row must be a non-empty associative array');
            }
        }
        $allowFields = $option['field'] ?? [];
        if (!empty($allowFields)) {
            foreach ($insertData as &$row) {
                foreach ($row as $column => $value) {
                    if (!in_array($column, $allowFields, true)) { unset($row[$column]); }
                }
            }
            unset($row);
        }
        $insertData = WriteCompiler::normalizeBatch($insertData);

        $this->buildInsert($tableName, $insertData, isset($option['replace']) ? 'REPLACE' : 'INSERT');
        $this->reset();
        return $this;
    }

    /** Batch insert/update using the table's primary/unique key constraints. */
    public function upsert(mixed $tableName, array $rows, array $updateColumns): QueryBuilder
    {
        $rows = WriteCompiler::normalizeBatch($rows);
        if ($updateColumns === []) { throw new \InvalidArgumentException('Upsert requires update columns'); }
        foreach ($updateColumns as $key => $value) {
            TableReference::identifier(is_int($key) ? $value : $key);
            if (is_int($key) && (!is_string($value) || !array_key_exists($value, $rows[0]))) {
                throw new \InvalidArgumentException('Implicit upsert columns must exist in every row');
            }
        }
        $this->onDuplicate($updateColumns);
        return $this->insertAll($tableName, $rows);
    }

    /** REPLACE插入 */
    public function replace(mixed $tableName, mixed $insertData): QueryBuilder
    {
        $this->buildInsert($tableName, $insertData, 'REPLACE');
        $this->reset();
        return $this;
    }

    /** onDuplicate插入 */
    public function onDuplicate(mixed $updateColumns): QueryBuilder
    {
        $this->pending['updateColumns'] = $updateColumns;
        return $this;
    }

    /** update查询 */
    public function update(mixed $tableName, mixed $tableData, mixed $numRows = null): ?QueryBuilder
    {
        if ($this->isSubQuery) {
            return null;
        }

        $this->pending['tableName'] = $tableName;
        $this->pending['sql'] = "UPDATE " . TableReference::parse($this->pending['tableName'], $this->prefix);
        if (is_array($this->pending['fields'])) {
            foreach ($tableData as $key => $val) {
                if (!in_array($key, $this->pending['fields'])) {
                    unset($tableData[$key]);
                }
            }
        }
        $this->compilePending($numRows ?? $this->pending['limit'], $tableData);
        $this->reset();
        return $this;
    }

    /** delete查询 */
    public function delete(mixed $tableName, mixed $numRows = null): ?QueryBuilder
    {
        if ($this->isSubQuery) {
            return null;
        }
        $this->pending['tableName'] = $tableName;
        $table = TableReference::parse($this->pending['tableName'], $this->prefix);
        if (count($this->pending['joins'])) {
            $this->pending['sql'] = "DELETE " . preg_replace('/.* (.*)/', '$1', $table) . " FROM " . $table;
        } else {
            $this->pending['sql'] = "DELETE FROM " . $table;
        }
        $this->compilePending($numRows ?? $this->pending['limit']);
        $this->reset();
        return $this;
    }

    //---------- 辅助方法 ---------- //

    /** 重置构造器 */
    public function reset(): QueryBuilder
    {
        if ($this->pending['isCompiled']) {
            $this->compiled = new CompiledQuery(
                $this->pending['sql'],
                $this->pending['parameters'],
                $this->pending['queryOptions'],
                $this->pending['transactionOp'],
            );
        }
        $this->pending = PendingQuery::DEFAULTS;
        return $this;
    }

    /** MysqlFunc/Now 快捷方法 */
    public static function now(mixed $diff = null, mixed $func = "NOW()"): array
    {
        return ExpressionFactory::now($diff, $func);
    }

    /** MysqlInc表达式 */
    public static function inc(mixed $num = 1): array
    {
        return ExpressionFactory::inc($num);
    }

    /** MysqlDec表达式 */
    public static function dec(mixed $num = 1): array
    {
        return ExpressionFactory::dec($num);
    }

    /** MysqlNot表达式 */
    public static function not(mixed $col = null): array
    {
        return ExpressionFactory::not($col);
    }

    /** MysqlFunc表达式 */
    public static function func(mixed $expr, mixed $bindParams = null): array
    {
        return ExpressionFactory::func($expr, $bindParams);
    }

    /** 创建一个子查询 */
    public static function subQuery(string|null $subQueryAlias = null): QueryBuilder
    {
        return new static(true, $subQueryAlias);
    }

    //---------- 输出方法 ---------- //

    /** 获取构建的SQL */
    public function getLastPrepareQuery(): ?string
    {
        return $this->compiled?->sql;
    }

    /** 获取最近一次构建的事务操作标记。 */
    public function getLastTransactionOp(): ?int
    {
        return $this->compiled?->transactionOp;
    }

    /** 获取最后绑定的参数 */
    public function getLastBindParams(): array
    {
        return $this->compiled?->parameters ?? [];
    }

    /** 获取最后的查询参数 */
    function getLastQueryOptions(): array
    {
        return $this->compiled?->options ?? [];
    }

    /** 获取当前查询选项。 */
    function getQueryOptions(): array
    {
        return $this->pending['queryOptions'];
    }

    /** 获取构建的SQL(已替换占位符) */
    public function getLastQuery(): mixed
    {
        return $this->lastRenderedQuery;
    }

    /** 获取子查询 */
    public function getSubQuery(): ?array
    {
        if (!$this->isSubQuery) {
            return null;
        }
        $val = [
            'query'  => $this->compiled?->sql,
            'params' => $this->compiled?->parameters ?? [],
            'alias'  => $this->subQueryAlias
        ];
        return $val;
    }

    /** 当前是否子查询 */
    public function isSubQuery(): bool
    {
        return $this->isSubQuery;
    }

    /** 构造开始事务的 SQL。 */
    public function startTransaction(): void
    {
        $this->pending['transactionOp'] = self::TS_OP_START;
        $this->raw('start transaction');
    }

    /** 构造提交事务的 SQL。 */
    public function commit(): void
    {
        $this->pending['transactionOp'] = self::TS_OP_COMMIT;
        $this->raw('commit');
    }

    /** 构造回滚事务的 SQL。 */
    public function rollback(): void
    {
        $this->pending['transactionOp'] = self::TS_OP_ROLLBACK;
        $this->raw("rollback");
    }

    /** 保存原始 SQL 和绑定参数。 */
    public function raw(mixed $sql, mixed $param = []): QueryBuilder
    {
        $parameters = is_array($param) ? array_map(static fn($value) => $value, array_values($param)) : [];
        $rendered = $parameters === [] ? $sql : SqlFormatter::render($sql, $parameters);
        $this->lastUnionOperandNeedsParentheses = true;
        $this->pending['sql'] = $sql;
        $this->pending['parameters'] = $parameters;
        $this->lastRenderedQuery = $rendered;
        $this->pending['isCompiled'] = true;
        $this->reset();
        return $this;
    }

    private function compilePending(mixed $numRows = null, mixed $tableData = null): void
    {
        $buffer = new SqlBuffer($this->pending['sql'], $this->pending['parameters'], $this->pending['tableName']);
        $parts = PendingQuery::parts($this->pending);
        try {
            $operation = (new QueryCompiler())->compile($buffer, $parts, $numRows, $tableData);
            $rendered = SqlFormatter::render($buffer->sql, $buffer->parameters);
        } catch (\Throwable $error) {
            $this->pending['sql'] = null;
            $this->pending['parameters'] = [];
            $this->pending['isCompiled'] = false;
            throw $error;
        }
        $this->pending['sql'] = $buffer->sql;
        $this->pending['parameters'] = $buffer->parameters;
        $this->pending['transactionOp'] = $operation ?? $this->pending['transactionOp'];
        $this->lastRenderedQuery = $rendered;
        $this->pending['isCompiled'] = true;
    }

    /** 构建插入的前半部分 */
    private function buildInsert(mixed $tableName, mixed $insertData, mixed $operation): void
    {
        if ($this->isSubQuery) {
            return;
        }
        $this->pending['tableName'] = $tableName;
        $this->pending['sql'] = $operation . " " . implode(' ', $this->pending['queryOptions']) . " INTO " . TableReference::parse($this->pending['tableName'], $this->prefix);
        $this->compilePending(null, $insertData);
    }

}
