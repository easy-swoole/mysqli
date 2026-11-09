<?php

namespace EasySwoole\Mysqli;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\QueryBuilder\{
    ComparisonOperator,
    ConditionConjunction,
    ExpressionFactory,
    JoinType,
    LockWaitPolicy,
    QueryCompiler,
    QueryParts,
    SelectCompiler,
    SortDirection,
    SqlBuffer,
    SqlFormatter,
    TableLockMode,
    TableReference,
    WriteCompiler
};

/** Public fluent API; SQL compilation is delegated to internal collaborators. */
class QueryBuilder
{
    private $prefix = '';

    const TS_OP_START = 1;
    const TS_OP_COMMIT = 2;
    const TS_OP_ROLLBACK = 3;
    const TS_OP_LOCK_TABLE = 4;
    const TS_OP_UNLOCK_TABLE = 5;
    const TS_OP_LOCK_IN_SHARE = 6;
    const TS_OP_LOCK_FOR_UPDATE = 7;

    /*
     * 构造结果
     */
    private $lastPrepareQuery = null;
    private $lastBindParams = [];
    private $lastQueryOptions = [];
    private $lastQuery;
    private $lastTransactionOp = null;
    //锁标记
    private $pendingTransactionOp = null;

    // 以下为查询条件容器
    private $joins = [];
    private $whereConditions = [];
    private $unionBranches = [];
    private $joinConditions = [];
    private $havingConditions = [];
    private $ordering = [];
    private bool $lastUnionOperandNeedsParentheses = true;
    private $groups = [];
    private $queryOptions = [];

    // 以下为查询配置项
    private $tableLockMethod = "READ";
    private $pendingParameters = [];
    private $isSubQuery = false;
    private $updateColumns = null;
    private $tableName = '';
    private $forUpdate = false;
    private $lockInShareMode = false;
    private $selectLockOption = '';
    private $subQueryAlias = '';
    private $limit = null;
    private $fields = '*';
    private array $forceIndexes = [];
    private array $useIndexes = [];
    private array $ignoreIndexes = [];
    /*
     * 查询构造结构
     */
    private $pendingSql;

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
            $this->limit = [$one, $two];
        } else {
            $this->limit = $one;
        }
        return $this;
    }

    /** 对所选列的组合去重，仅影响下一次构建的查询。 */
    public function distinct(bool $isDistinct = true): QueryBuilder
    {
        $removeOption = $isDistinct ? 'ALL' : 'DISTINCT';
        $this->queryOptions = array_values(array_filter(
            $this->queryOptions,
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
        $this->forceIndexes = TableReference::normalizeIndexes($indexes);
        if ($this->forceIndexes !== []) {
            $this->useIndexes = [];
        }
        return $this;
    }

    /** 建议下一次 SELECT 主表使用的索引；与 FORCE INDEX 互斥。 */
    public function useIndex(string|array $indexes): QueryBuilder
    {
        $this->useIndexes = TableReference::normalizeIndexes($indexes);
        if ($this->useIndexes !== []) {
            $this->forceIndexes = [];
        }
        return $this;
    }

    /** 排除下一次 SELECT 主表的索引。 */
    public function ignoreIndex(string|array $indexes): QueryBuilder
    {
        $this->ignoreIndexes = TableReference::normalizeIndexes($indexes);
        return $this;
    }

    /** 设置查询字段或写入时允许的字段。 */
    public function fields($fields): QueryBuilder
    {
        if (!is_array($fields)) {
            $fields = [$fields];
        }
        $this->fields = $fields;
        return $this;
    }

    /** Add a parameterized SELECT expression; the first call replaces the implicit default *. */
    public function selectRaw(string $sql, array $parameters = []): QueryBuilder
    {
        $expression = new Expression($sql, $parameters);
        $fields = $this->fields === '*' ? [] : (is_array($this->fields) ? $this->fields : [$this->fields]);
        $fields[] = $expression;
        $this->fields = $fields;
        return $this;
    }

    public static function rawExpression(string $sql, array $parameters = []): Expression
    {
        return new Expression($sql, $parameters);
    }

    public function whereRaw(string $sql, array $parameters = [], ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addRawCondition($this->whereConditions, $sql, $parameters, $cond);
    }

    public function havingRaw(string $sql, array $parameters = [], ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addRawCondition($this->havingConditions, $sql, $parameters, $cond);
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
        return $this->addColumnCondition($this->whereConditions, $first, $second, $operator, $cond);
    }

    public function orWhereColumn(string $first, string $second, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->whereColumn($first, $second, $operator, 'OR');
    }

    public function havingColumn(string $first, string $second, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addColumnCondition($this->havingConditions, $first, $second, $operator, $cond);
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
        if (!is_array($this->fields)) {
            $field = [$this->fields];
        } else {
            $field = $this->fields;
        }
        return $field;
    }

    /** Where条件 */
    public function where($whereProp, $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND')
    {
        $operator = $this->comparisonOperator($operator);
        $cond = $this->conditionConjunction($cond, false);
        if (count($this->whereConditions) == 0) {
            $cond = '';
        }
        $this->whereConditions[] = [$cond, $whereProp, $operator, $whereValue];
        return $this;
    }

    /** WhereOr条件 */
    public function orWhere($whereProp, $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=')
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
        // Quote each identifier segment and bind the complete LIKE pattern.
        return $this->where(implode('.', array_map(
            static fn(string $part): string => '`' . str_replace('`', '``', $part) . '`',
            explode('.', $column)
        )) . ' LIKE ?', [$pattern], '=', $cond);
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
        $this->whereConditions[] = ['exists' => $subquery, 'operator' => $operator, 'concat' => $this->whereConditions === [] ? '' : $cond];
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
        return $this->addConditionGroup($this->whereConditions, $callback, $concat, 'whereConditions', 'WHERE');
    }

    public function havingGroup(callable $callback): QueryBuilder
    {
        return $this->addConditionGroup($this->havingConditions, $callback, 'AND', 'havingConditions', 'HAVING');
    }

    public function orHavingGroup(callable $callback): QueryBuilder
    {
        return $this->addConditionGroup($this->havingConditions, $callback, 'OR', 'havingConditions', 'HAVING');
    }

    public function joinWhereGroup(string $table, callable $callback): QueryBuilder
    {
        $key = $this->prefix . $table;
        $conditions = $this->joinConditions[$key] ?? [];
        $this->addConditionGroup($conditions, $callback, 'AND', 'whereConditions', 'ON', true);
        $this->joinConditions[$key] = $conditions;
        return $this;
    }

    public function joinOrWhereGroup(string $table, callable $callback): QueryBuilder
    {
        $key = $this->prefix . $table;
        $conditions = $this->joinConditions[$key] ?? [];
        $this->addConditionGroup($conditions, $callback, 'OR', 'whereConditions', 'ON', true);
        $this->joinConditions[$key] = $conditions;
        return $this;
    }

    private function addConditionGroup(array &$conditions, callable $callback, string $concat, string $source, string $label, bool $on = false): QueryBuilder
    {
        $group = new static();
        $group->setPrefix($this->prefix);
        $callback($group);
        if ($group->{$source} === []) {
            throw new \InvalidArgumentException($label . ' groups must contain at least one condition');
        }
        $conditions[] = ['group' => $group->{$source}, 'concat' => !$on && $conditions === [] ? '' : $concat];
        return $this;
    }

    public function orderByRaw(string $sql, array $parameters = []): QueryBuilder
    {
        $expression = new Expression($sql, $parameters);
        $key = 'raw:' . serialize([$sql, $expression->getParameters()]);
        $this->ordering[$key] = ['field' => $expression, 'direction' => '', 'params' => []];
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
    public function orderBy($orderByField, SortDirection|string $orderbyDirection = "DESC", $customFieldsOrRegExp = null)
    {
        [$key, $order] = SelectCompiler::order($orderByField, $orderbyDirection, $customFieldsOrRegExp, $this->prefix, $this->tableName);
        $this->ordering[$key] = $order;
        return $this;
    }

    /** GroupBy条件 */
    public function groupBy($groupByField)
    {
        $groupByField = preg_replace("/[^-a-z0-9\.\(\),_\* <>=!%`\']+/i", '', $groupByField);
        $this->groups[] = $groupByField;
        return $this;
    }

    /** Having条件 */
    public function having($havingProp, $havingValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND')
    {
        $operator = $this->comparisonOperator($operator);
        $cond = $this->conditionConjunction($cond, false);
        if (is_array($havingValue) && ($key = key($havingValue)) != "0") {
            $operator = $this->comparisonOperator($key);
            $havingValue = $havingValue[$key];
        }
        if (count($this->havingConditions) == 0) {
            $cond = '';
        }
        $this->havingConditions[] = [$cond, $havingProp, $operator, $havingValue];
        return $this;
    }

    /** OrHaving条件 */
    public function orHaving($havingProp, $havingValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=')
    {
        return $this->having($havingProp, $havingValue, $operator, 'OR');
    }

    /** Join查询 */
    public function join($joinTable, $joinCondition, JoinType|string $joinType = '')
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
        $this->joins[] = [$joinType, $joinTable, $joinCondition, $conditionKey];
        return $this;
    }

    /** 为关联表添加 AND 条件。 Join xx where xx */
    public function joinWhere($whereJoin, $whereProp, $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND')
    {
        $operator = $this->comparisonOperator($operator);
        $cond = $this->conditionConjunction($cond, false);
        $this->joinConditions[$this->prefix . $whereJoin][] = Array($cond, $whereProp, $operator, $whereValue);
        return $this;
    }

    /** 为关联表添加 OR 条件。 Join xx or where xx */
    public function joinOrWhere($whereJoin, $whereProp, $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND')
    {
        return $this->joinWhere($whereJoin, $whereProp, $whereValue, $operator, 'OR');
    }

    public function joinOn(string $table, string $first, string $second, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        $key = $this->prefix . $table;
        $conditions = $this->joinConditions[$key] ?? [];
        $this->addColumnCondition($conditions, $first, $second, $operator, $cond, true);
        $this->joinConditions[$key] = $conditions;
        return $this;
    }

    public function joinOrOn(string $table, string $first, string $second, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->joinOn($table, $first, $second, $operator, 'OR');
    }

    /** 构建UNION语句 */
    public function union($cond, $isUnionAll = false)
    {
        if ($cond instanceof QueryBuilder) {
            $this->unionBranches[] = [$cond->getLastPrepareQuery(), $cond->getLastBindParams(), $isUnionAll,
                $cond->lastUnionOperandNeedsParentheses];
        } elseif (is_string($cond)) {
            // Raw SQL may already contain local ordering, pagination or another UNION.
            $this->unionBranches[] = [$cond, [], $isUnionAll, true];
        }
        return $this;
    }

    /** LockInShareModel锁定(InnoDb) */
    public function lockInShareMode($isLock = true)
    {
        if ($isLock) {
            $this->setQueryOption(['LOCK IN SHARE MODE']);
        } else {
            $this->lockInShareMode = false;
        }
        return $this;
    }

    /** SELECT FOR UPDATE锁定(InnoDb) */
    public function selectForUpdate($isLock = true, LockWaitPolicy|string|null $option = null)
    {
        if ($isLock) {
            $hasOption = $option instanceof LockWaitPolicy || (bool) $option;
            $lockOption = $hasOption ? $this->lockWaitOption($option) : null;
            $this->setQueryOption(['FOR UPDATE']);
            if ($hasOption) {
                $this->selectLockOption = $lockOption;
            }
        } else {
            $this->forUpdate = false;
            $this->selectLockOption = false;
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
    public function setLockTableMode(TableLockMode|string $method)
    {
        $mode = $method instanceof TableLockMode ? $method : TableLockMode::tryFrom(strtoupper($method));
        if ($mode === null) {
            throw new Exception("Bad lock type: Can be either READ or WRITE for table {$this->tableName}");
        }
        $this->tableLockMethod = $mode->value;
        return $this;
    }

    /** 获得表锁 */
    public function lockTable($table)
    {
        $this->pendingTransactionOp = self::TS_OP_LOCK_TABLE;
        $this->pendingSql = "LOCK TABLES";
        // Is the table an array?
        if (gettype($table) == "array") {
            // Loop trough it and attach it to the query
            foreach ($table as $key => $value) {
                if (gettype($value) == "string") {
                    if ($key > 0) {
                        $this->pendingSql .= ",";
                    }
                    $this->pendingSql .= " " . $this->prefix . $value . " " . $this->tableLockMethod;
                    $this->lastQuery = $this->pendingSql;
                }
            }
        } else {
            // Build the table prefix
            $table = $this->prefix . $table;
            // Build the query
            $this->pendingSql = "LOCK TABLES " . $table . " " . $this->tableLockMethod;
            $this->lastQuery = $this->pendingSql;
        }
        $this->reset();
        return $this;
    }

    /** 释放表锁 */
    public function unlockTable()
    {
        $this->pendingTransactionOp = self::TS_OP_UNLOCK_TABLE;
        $this->pendingSql = "UNLOCK TABLES";
        $this->lastQuery = $this->pendingSql;
        $this->reset();
        return $this;
    }

    /** 设置查询条件 */
    public function setQueryOption($options)
    {
        $allowedOptions = ['ALL', 'DISTINCT', 'DISTINCTROW', 'HIGH_PRIORITY', 'STRAIGHT_JOIN', 'SQL_SMALL_RESULT',
            'SQL_BIG_RESULT', 'SQL_BUFFER_RESULT', 'SQL_CACHE', 'SQL_NO_CACHE', 'SQL_CALC_FOUND_ROWS',
            'LOW_PRIORITY', 'IGNORE', 'QUICK', 'FOR UPDATE', 'LOCK IN SHARE MODE'];
        if (!is_array($options)) {
            $options = [$options];
        }
        foreach ($options as $option) {
            $option = strtoupper($option);
            if (!in_array($option, $allowedOptions)) {
                throw new Exception('Wrong query option: ' . $option ." for table {$this->tableName}");
            }
            if ($option == 'FOR UPDATE') {
                $this->forUpdate = true;
            } elseif ($option == 'LOCK IN SHARE MODE') {
                $this->lockInShareMode = true;
            } else {
                if (!in_array($option,$this->queryOptions)){
                    $this->queryOptions[] = $option;
                }
            }
        }
        return $this;
    }

    /** 设置排他锁查询的等待选项。 */
    public function setSelectLockOption(LockWaitPolicy|string $option)
    {
        $this->selectLockOption = $this->lockWaitOption($option);
        return $this;
    }

    /** 设置表前缀 */
    public function setPrefix($prefix = '')
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

    /** SELECT查询 */
    public function get($tableName, $numRows = null, $columns = null)
    {
        if ($columns == null) {
            $columns = $this->fields;
        }
        $select = new SqlBuffer('', $this->pendingParameters, $tableName);
        $column = SelectCompiler::columns($columns, $select);
        $this->pendingParameters = $select->parameters;

        $this->tableName = $tableName;
        $this->pendingSql = 'SELECT ' . implode(' ', $this->queryOptions) . ' ' .
            $column . " FROM " . TableReference::parse($this->tableName, $this->prefix);
        $this->pendingSql .= TableReference::indexHints(['FORCE' => $this->forceIndexes,
            'USE' => $this->useIndexes, 'IGNORE' => $this->ignoreIndexes]);
        if ($numRows === null) {
            $numRows = $this->limit;
        }
        $this->compilePending($numRows);
        $this->lastUnionOperandNeedsParentheses = $this->ordering !== [] || $numRows !== null
            || $this->unionBranches !== [] || $this->forUpdate || $this->lockInShareMode;
        $this->reset();
        return $this;
    }

    /** SELECT LIMIT1查询 */
    public function getOne($tableName, $columns = '*')
    {
        if ($columns === '*'){
            $columns = $this->fields;
        }
        return $this->get($tableName, 1, $columns);
    }

    /** SELECT 获取某一列的数据 */
    public function getColumn($tableName, $columns = null, $numRows = null): ?QueryBuilder
    {
        if (!is_string($columns) && !($columns instanceof Expression)) {
            $columns = $this->fields;
            if (is_array($columns) && count($columns) > 0) {
                $first = reset($columns);
                $columns = $first instanceof Expression ? $first : explode(',', str_replace(' ', '', $first))[0];
            }
        }
        return $this->get($tableName, $numRows, $columns);
    }

    /** SELECT 获取某一列第一行的数据 */
    public function getScalar($tableName, $columns = null): ?QueryBuilder
    {
        return $this->getColumn($tableName, $columns, 1);
    }

    /** 插入数据 */
    public function insert($tableName, $insertData)
    {
        if (is_array($this->fields)) {
            foreach ($insertData as $key => $val) {
                if (!in_array($key, $this->fields)) {
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
    public function insertAll($tableName, $insertData, $option = [])
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
    public function upsert($tableName, array $rows, array $updateColumns): QueryBuilder
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
    public function replace($tableName, $insertData)
    {
        $this->buildInsert($tableName, $insertData, 'REPLACE');
        $this->reset();
        return $this;
    }

    /** onDuplicate插入 */
    public function onDuplicate($updateColumns)
    {
        $this->updateColumns = $updateColumns;
        return $this;
    }

    /** update查询 */
    public function update($tableName, $tableData, $numRows = null)
    {
        if ($this->isSubQuery) {
            return;
        }

        $this->tableName = $tableName;
        $this->pendingSql = "UPDATE " . TableReference::parse($this->tableName, $this->prefix);
        if (is_array($this->fields)) {
            foreach ($tableData as $key => $val) {
                if (!in_array($key, $this->fields)) {
                    unset($tableData[$key]);
                }
            }
        }
        $this->compilePending($numRows ?? $this->limit, $tableData);
        $this->reset();
        return $this;
    }

    /** delete查询 */
    public function delete($tableName, $numRows = null)
    {
        if ($this->isSubQuery) {
            return;
        }
        $this->tableName = $tableName;
        $table = TableReference::parse($this->tableName, $this->prefix);
        if (count($this->joins)) {
            $this->pendingSql = "DELETE " . preg_replace('/.* (.*)/', '$1', $table) . " FROM " . $table;
        } else {
            $this->pendingSql = "DELETE FROM " . $table;
        }
        $this->compilePending($numRows ?? $this->limit);
        $this->reset();
        return $this;
    }

    //---------- 辅助方法 ---------- //

    /** 重置构造器 */
    public function reset()
    {
        $this->lastPrepareQuery = $this->pendingSql;
        $this->lastBindParams = $this->pendingParameters;
        $this->lastQueryOptions = $this->queryOptions;
        $this->lastTransactionOp = $this->pendingTransactionOp;

        $this->pendingTransactionOp = null;

        $this->limit = null;
        $this->fields = '*';
        $this->forceIndexes = [];
        $this->useIndexes = [];
        $this->ignoreIndexes = [];
        $this->unionBranches = [];
        $this->whereConditions = [];
        $this->havingConditions = [];
        $this->joins = [];
        $this->joinConditions = [];
        $this->ordering = [];
        $this->groups = [];
        $this->pendingParameters = [];
        $this->pendingSql = null;
        $this->queryOptions = array();
        $this->forUpdate = false;
        $this->lockInShareMode = false;
        $this->selectLockOption = '';
        $this->tableName = '';
        $this->updateColumns = null;
        return $this;
    }

    /** MysqlFunc/Now 快捷方法 */
    public static function now($diff = null, $func = "NOW()")
    {
        return ExpressionFactory::now($diff, $func);
    }

    /** MysqlInc表达式 */
    public static function inc($num = 1)
    {
        return ExpressionFactory::inc($num);
    }

    /** MysqlDec表达式 */
    public static function dec($num = 1)
    {
        return ExpressionFactory::dec($num);
    }

    /** MysqlNot表达式 */
    public static function not($col = null)
    {
        return ExpressionFactory::not($col);
    }

    /** MysqlFunc表达式 */
    public static function func($expr, $bindParams = null)
    {
        return ExpressionFactory::func($expr, $bindParams);
    }

    /** 创建一个子查询 */
    public static function subQuery(string|null $subQueryAlias = null)
    {
        return new static(true, $subQueryAlias);
    }

    //---------- 输出方法 ---------- //

    /** 获取构建的SQL */
    public function getLastPrepareQuery(): ?string
    {
        return $this->lastPrepareQuery;
    }

    /** 获取最近一次构建的事务操作标记。 */
    public function getLastTransactionOp(): ?int
    {
        return $this->lastTransactionOp;
    }

    /** 获取最后绑定的参数 */
    public function getLastBindParams()
    {
        return $this->lastBindParams;
    }

    /** 获取最后的查询参数 */
    function getLastQueryOptions(): array
    {
        return $this->lastQueryOptions;
    }

    /** 获取当前查询选项。 */
    function getQueryOptions(): array
    {
        return $this->queryOptions;
    }

    /** 获取构建的SQL(已替换占位符) */
    public function getLastQuery()
    {
        return $this->lastQuery;
    }

    /** 获取子查询 */
    public function getSubQuery()
    {
        if (!$this->isSubQuery) {
            return null;
        }
        $val = [
            'query'  => $this->lastPrepareQuery,
            'params' => $this->lastBindParams,
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
    public function startTransaction()
    {
        $this->pendingTransactionOp = self::TS_OP_START;
        $this->raw('start transaction');
    }

    /** 构造提交事务的 SQL。 */
    public function commit()
    {
        $this->pendingTransactionOp = self::TS_OP_COMMIT;
        $this->raw('commit');
    }

    /** 构造回滚事务的 SQL。 */
    public function rollback()
    {
        $this->pendingTransactionOp = self::TS_OP_ROLLBACK;
        $this->raw("rollback");
    }

    /** 保存原始 SQL 和绑定参数。 */
    public function raw($sql, $param = [])
    {
        $this->lastUnionOperandNeedsParentheses = true;
        $this->pendingSql = $sql;
        if (!empty($param) && is_array($param)){
            $this->pendingParameters = array_merge($this->pendingParameters, array_values($param));
            $this->lastQuery = SqlFormatter::render($this->pendingSql, $this->pendingParameters);
        } else {
            $this->lastQuery = $sql;
        }
        $this->reset();
        return $this;
    }

    private function compilePending($numRows = null, $tableData = null)
    {
        $buffer = new SqlBuffer($this->pendingSql, $this->pendingParameters, $this->tableName);
        $parts = new QueryParts(
            joins: $this->joins,
            joinConditions: $this->joinConditions,
            where: $this->whereConditions,
            groups: $this->groups,
            having: $this->havingConditions,
            unions: $this->unionBranches,
            ordering: $this->ordering,
            updateColumns: $this->updateColumns,
            forUpdate: $this->forUpdate,
            lockInShareMode: $this->lockInShareMode,
            selectLockOption: $this->selectLockOption,
        );
        try {
            $operation = (new QueryCompiler())->compile($buffer, $parts, $numRows, $tableData);
        } finally {
            // Preserve reset()/raw() behavior after a failed compilation as well as on success.
            $this->pendingSql = $buffer->sql;
            $this->pendingParameters = $buffer->parameters;
        }
        $this->pendingTransactionOp = $operation ?? $this->pendingTransactionOp;
        $this->lastQuery = SqlFormatter::render($buffer->sql, $buffer->parameters);
    }

    /** 构建插入的前半部分 */
    private function buildInsert($tableName, $insertData, $operation)
    {
        if ($this->isSubQuery) {
            return;
        }
        $this->tableName = $tableName;
        $this->pendingSql = $operation . " " . implode(' ', $this->queryOptions) . " INTO " . TableReference::parse($this->tableName, $this->prefix);
        $this->compilePending(null, $insertData);
    }

}
