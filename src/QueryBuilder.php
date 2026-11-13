<?php

namespace EasySwoole\Mysqli;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\QueryBuilder\{
    CompiledQuery,
    CountCompilation,
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
 * 公开链式接口；查询规划和 SQL 编译委托给内部协作类。
 * @phpstan-import-type State from PendingQuery
 */
class QueryBuilder
{
    private mixed $prefix = '';
    private string $tableLockMethod = 'READ';
    private bool $isSubQuery = false;
    private ?string $subQueryAlias = '';

    /** @var State 写时复制状态；克隆对象不会共享可变状态对象。 */
    private array $pending = PendingQuery::DEFAULTS;
    private ?CompiledQuery $compiled = null;
    // null 为直接调用 count()，false 为可直接编译的分页副本。
    private QueryBuilder|false|null $countSource = null;
    private ?CountCompilation $countCompilation = null;
    private bool $noBackslashEscapes = false;
    private bool $ansiQuotes = false;
    private ?CompiledQuery $readyToPublish = null;
    // 调试读取先于可重写的 reset()，并保留最近一次成功构建。
    private ?CompiledQuery $lastBuilt = null;

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

    // 投影、索引与分页

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

    /** 获取显式配置的字段，未配置时返回 null。 */
    public function getField(): ?array
    {
        return $this->pending['fields'];
    }

    /** 添加带绑定参数的 SELECT 表达式；首次调用替换隐式默认字段 *。 */
    public function selectRaw(string $sql, array $parameters = []): QueryBuilder
    {
        $expression = new Expression($sql, $parameters);
        $this->pending['fields'][] = $expression;
        return $this;
    }

    // WHERE 条件

    public function whereRaw(string $sql, array $parameters = [], ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addRawCondition($this->pending['whereConditions'], $sql, $parameters, $cond);
    }

    public function whereColumn(string $first, string $second, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addColumnCondition($this->pending['whereConditions'], $first, $second, $operator, $cond);
    }

    public function orWhereColumn(string $first, string $second, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->whereColumn($first, $second, $operator, 'OR');
    }

    /** WHERE 条件。 */
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

    /** 通过 OR 连接的 WHERE 条件。 */
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
        return $this->addExistsCondition($subquery, 'EXISTS', $cond);
    }

    public function whereNotExists(QueryBuilder $subquery, ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addExistsCondition($subquery, 'NOT EXISTS', $cond);
    }

    /** 将全部已有 WHERE 条件作为一组，便于继续追加必须同时满足的约束。 */
    public function groupExistingWhere(): QueryBuilder
    {
        if ($this->pending['whereConditions'] !== []) {
            $this->pending['whereConditions'] = [['group' => $this->pending['whereConditions'], 'concat' => '']];
        }
        return $this;
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

    // 排序、分组与 HAVING 条件

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

    /** ORDER BY 排序条件。 */
    public function orderBy(mixed $orderByField, SortDirection|string $orderbyDirection = "DESC", mixed $customFieldsOrRegExp = null): QueryBuilder
    {
        [$key, $order] = SelectCompiler::order($orderByField, $orderbyDirection, $customFieldsOrRegExp, $this->pending['tableName']);
        $this->pending['ordering'][$key] = $order;
        return $this;
    }

    /** 清除待构建查询的全部排序，保留其他条件。 */
    public function reorder(): QueryBuilder
    {
        $this->pending['ordering'] = [];
        return $this;
    }

    /** GROUP BY 分组条件。 */
    public function groupBy(mixed $groupByField): QueryBuilder
    {
        $this->pending['groups'][] = $groupByField;
        return $this;
    }

    /** HAVING 聚合筛选条件。 */
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

    /** 通过 OR 连接的 HAVING 条件。 */
    public function orHaving(mixed $havingProp, mixed $havingValue = ConditionValue::Raw, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->having($havingProp, $havingValue, $operator, 'OR');
    }

    public function havingRaw(string $sql, array $parameters = [], ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addRawCondition($this->pending['havingConditions'], $sql, $parameters, $cond);
    }

    public function havingColumn(string $first, string $second, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->addColumnCondition($this->pending['havingConditions'], $first, $second, $operator, $cond);
    }

    public function orHavingColumn(string $first, string $second, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->havingColumn($first, $second, $operator, 'OR');
    }

    public function havingGroup(callable $callback): QueryBuilder
    {
        return $this->addConditionGroup($this->pending['havingConditions'], $callback, 'AND', 'havingConditions', 'HAVING');
    }

    public function orHavingGroup(callable $callback): QueryBuilder
    {
        return $this->addConditionGroup($this->pending['havingConditions'], $callback, 'OR', 'havingConditions', 'HAVING');
    }

    // JOIN 与 UNION

    /** JOIN 关联查询。 */
    public function join(mixed $joinTable, mixed $joinCondition, JoinType|string $joinType = ''): QueryBuilder
    {
        $joinType = $joinType instanceof JoinType ? $joinType->value : strtoupper(trim($joinType));
        if (JoinType::tryFrom($joinType) === null && $joinType !== 'OUTER') {
            throw new Exception('Wrong JOIN type: ' . $joinType);
        }
        $conditionKey = null;
        if ($joinTable instanceof Expression) {
            $conditionKey = $joinTable->getSql();
        } elseif (!is_object($joinTable)) {
            $conditionKey = (string)$joinTable;
            $joinTable = TableReference::resolve($joinTable, $this->prefix, quoteBare: false)['sql'];
        }
        $this->pending['joins'][] = [$joinType, $joinTable, $joinCondition, $conditionKey];
        return $this;
    }

    /** 为关联表的 ON 子句追加 AND 条件。 */
    public function joinWhere(mixed $whereJoin, mixed $whereProp, mixed $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        $operator = $this->comparisonOperator($operator);
        $cond = $this->conditionConjunction($cond, false);
        $this->pending['joinConditions'][(string)$whereJoin][] = [$cond, $whereProp, $operator, $whereValue];
        return $this;
    }

    /** 为关联表的 ON 子句追加 OR 条件。 */
    public function joinOrWhere(mixed $whereJoin, mixed $whereProp, mixed $whereValue = ConditionValue::Raw, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        return $this->joinWhere($whereJoin, $whereProp, $whereValue, $operator, 'OR');
    }

    public function joinOn(string $table, string $first, string $second, ComparisonOperator|string $operator = '=', ConditionConjunction|string $cond = 'AND'): QueryBuilder
    {
        $conditions = $this->pending['joinConditions'][$table] ?? [];
        $this->addColumnCondition($conditions, $first, $second, $operator, $cond, true);
        $this->pending['joinConditions'][$table] = $conditions;
        return $this;
    }

    public function joinOrOn(string $table, string $first, string $second, ComparisonOperator|string $operator = '='): QueryBuilder
    {
        return $this->joinOn($table, $first, $second, $operator, 'OR');
    }

    public function joinWhereGroup(string $table, callable $callback): QueryBuilder
    {
        return $this->addJoinGroup($table, $callback, 'AND');
    }

    public function joinOrWhereGroup(string $table, callable $callback): QueryBuilder
    {
        return $this->addJoinGroup($table, $callback, 'OR');
    }

    /** 构建UNION语句 */
    public function union(mixed $cond, mixed $isUnionAll = false): QueryBuilder
    {
        if (!is_bool($isUnionAll)) {
            throw new \TypeError('UNION ALL flag must be a boolean');
        }
        if ($cond instanceof QueryBuilder) {
            $sql = $cond->getLastPrepareQuery();
            if ($sql === null) {
                throw new \InvalidArgumentException('UNION requires a built query');
            }
            $this->pending['unionBranches'][] = [$sql, $cond->getLastBindParams(), $isUnionAll,
                $cond->compiled?->unionOperandNeedsParentheses ?? true];
        } else {
            if (!is_string($cond)) {
                throw new \TypeError('UNION requires a QueryBuilder or SQL string');
            }
            // 原始 SQL 可能已包含局部排序、分页或其他 UNION。
            $this->pending['unionBranches'][] = [$cond, [], $isUnionAll, true];
        }
        return $this;
    }

    // 锁与查询选项

    /** InnoDB 共享行锁。 */
    public function lockInShareMode(mixed $isLock = true): QueryBuilder
    {
        if (!is_bool($isLock)) {
            throw new \TypeError('Shared lock flag must be a boolean');
        }
        if ($isLock) {
            $this->setQueryOption(['LOCK IN SHARE MODE']);
        } else {
            $this->pending['lockInShareMode'] = false;
        }
        return $this;
    }

    /** InnoDB 排他行锁（SELECT FOR UPDATE）。 */
    public function selectForUpdate(mixed $isLock = true, LockWaitPolicy|string|null $option = null): QueryBuilder
    {
        if (!is_bool($isLock)) {
            throw new \TypeError('FOR UPDATE flag must be a boolean');
        }
        if ($isLock) {
            $hasOption = $option !== null && $option !== '';
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
        $tables = is_array($table) ? $table : [$table];
        if ($tables === []) {
            throw new \InvalidArgumentException('LOCK TABLES requires at least one table');
        }
        $references = [];
        foreach ($tables as $name) {
            if (!is_string($name) || trim($name) === '' || str_contains($name, "\0")) {
                throw new \InvalidArgumentException('Lock table names must be non-empty strings without NUL bytes');
            }
            $references[] = TableReference::parse($name, $this->prefix) . ' ' . $this->tableLockMethod;
        }
        return $this->completeBuild('LOCK TABLES ' . implode(', ', $references), [], self::TS_OP_LOCK_TABLE);
    }

    /** 释放表锁 */
    public function unlockTable(): QueryBuilder
    {
        return $this->completeBuild('UNLOCK TABLES', [], self::TS_OP_UNLOCK_TABLE);
    }

    /** 设置查询条件 */
    public function setQueryOption(mixed $options): QueryBuilder
    {
        if (!is_array($options)) {
            $options = [$options];
        }
        $normalized = [];
        foreach ($options as $option) {
            $option = strtoupper($option);
            if (!QueryCompiler::knowsOption($option)) {
                throw new Exception('Wrong query option: ' . $option . " for table {$this->pending['tableName']}");
            }
            $normalized[] = $option;
        }
        foreach ($normalized as $option) {
            if ($option == 'FOR UPDATE') {
                $this->pending['forUpdate'] = true;
            } elseif ($option == 'LOCK IN SHARE MODE') {
                $this->pending['lockInShareMode'] = true;
            } elseif (!in_array($option, $this->pending['queryOptions'], true)) {
                $this->pending['queryOptions'][] = $option;
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

    // SELECT 与聚合

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
        $this->readyToPublish = null;
        $scope = $this->countCompilation;
        $forCount = $scope?->active && $scope->quoteMode === null;
        $buffer = $this->selectBuffer($this->pending, $tableName, $columns, $forCount);
        $this->pending['tableName'] = $buffer->tableName;
        return $this->compilePending($buffer, 'SELECT', $numRows ?? $this->pending['limit']);
    }

    /** @param State $state */
    private function selectBuffer(array $state, mixed $tableName, mixed $columns = null, bool $forCount = false): SqlBuffer
    {
        // 等子类 get() 确定投影后，再决定普通行计数的默认列。
        $columns ??= $state['fields'] ?? ($forCount ? SelectPlanner::countDefaultColumns($state) : '*');
        return SelectPlanner::selectBuffer(
            $tableName, $columns, $state['queryOptions'], $this->prefix,
            $state['fromSource'], ['FORCE' => $state['forceIndexes'],
                'USE' => $state['useIndexes'], 'IGNORE' => $state['ignoreIndexes']],
        );
    }

    /** 统计筛选结果的行数或分组数，忽略整体排序、分页和锁。 */
    public function count(mixed $tableName, string $alias = 'aggregate'): QueryBuilder
    {
        // 先校验再克隆；子类 __clone() 执行完成后再应用查询计划。
        $quotedAlias = TableReference::identifier($alias);
        $source = $this->countSource;
        $this->countSource = null;
        $inner = $this->countSelectResult($tableName, $source);
        $sql = SelectPlanner::countSql($inner->sql, $quotedAlias);
        $this->pending['queryOptions'] = [];
        $this->pending['transactionOp'] = null;
        $previousCompilation = $this->countCompilation;
        $publication = new CountCompilation($inner->quoteMode);
        $this->countCompilation = $publication;
        try {
            return $this->raw($sql, $inner->parameters);
        } finally {
            $publication->active = false;
            $this->countCompilation = $previousCompilation;
        }
    }

    /** @internal 从模板创建计数查询。 */
    public function countQuery(?string $table, string $alias): QueryBuilder
    {
        $counter = clone $this;
        $counter->countSource = false;
        if (static::class !== self::class && $this->overridesCountSource()) {
            $counter->countSource = (new \ReflectionMethod($this, 'count'))->getDeclaringClass()->getName() === self::class
                ? clone $this : $counter;
        }
        try {
            return $counter->count($table, $alias);
        } finally {
            $counter->countSource = null;
        }
    }

    private function countSelectResult(mixed $tableName, QueryBuilder|false|null $source): CompiledQuery
    {
        if (static::class === self::class || $source === false) {
            $state = SelectPlanner::countSource($this->pending);
            return $this->compileQuery($state, $this->selectBuffer($state, $tableName, forCount: true), 'SELECT', null);
        }

        if ($source === $this) {
            // 还原计数副本的构建状态，同时保留子类 get() 钩子写入的属性。
            $state = [$this->pending, $this->compiled, $this->readyToPublish, $this->lastBuilt,
                $this->prefix, $this->tableLockMethod, $this->noBackslashEscapes, $this->ansiQuotes];
            try {
                return $this->compileCountSource($tableName, true);
            } finally {
                [$this->pending, $this->compiled, $this->readyToPublish, $this->lastBuilt,
                    $this->prefix, $this->tableLockMethod, $this->noBackslashEscapes, $this->ansiQuotes] = $state;
            }
        }
        return ($source ?? clone $this)->compileCountSource($tableName, $source !== null);
    }

    private function compileCountSource(mixed $tableName, bool $paging): CompiledQuery
    {
        $this->pending = SelectPlanner::countSource($this->pending);
        $previousCompilation = $this->countCompilation;
        $compilation = new CountCompilation();
        $compilation->skipResetHook = $paging;
        $this->countCompilation = $compilation;
        try {
            $source = $this->get($tableName);
            return new CompiledQuery($source->getLastPrepareQuery(), $source->getLastBindParams(),
                [], null, quoteMode: $source->compiled->quoteMode);
        } finally {
            $compilation->active = false;
            $this->countCompilation = $previousCompilation;
        }
    }

    /** 检查内层 SELECT 是否需要调用子类方法。 */
    private function overridesCountSource(): bool
    {
        foreach (['get', 'getLastPrepareQuery', 'getLastBindParams'] as $method) {
            if ((new \ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== self::class) {
                return true;
            }
        }
        return false;
    }

    /** 构建聚合 SELECT；UNION 整体聚合应先使用 fromSub()。 */
    public function aggregate(mixed $tableName, string $function, string|Expression $column, string $alias = 'aggregate'): QueryBuilder
    {
        $expression = SelectPlanner::aggregateExpression(
            $function, $column, $alias, $this->pending['unionBranches'] !== []
        );
        $fields = $this->pending['fields'] === null ? [] : $this->getField();
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
    public function getOne(mixed $tableName, mixed $columns = null): QueryBuilder
    {
        $columns ??= $this->pending['fields'] ?? '*';
        return $this->get($tableName, 1, $columns);
    }

    /** 指定拆分 SQL 表达式列表时是否按 NO_BACKSLASH_ESCAPES 解释引号。 */
    public function noBackslashEscapes(bool $enabled = true): QueryBuilder
    {
        $this->noBackslashEscapes = $enabled;
        return $this;
    }

    /** 会话开启 ANSI_QUOTES 时，将双引号按标识符规则处理。 */
    public function ansiQuotes(bool $enabled = true): QueryBuilder
    {
        $this->ansiQuotes = $enabled;
        return $this;
    }

    /** SELECT 获取某一列的数据 */
    public function getColumn(mixed $tableName, mixed $columns = null, mixed $numRows = null): ?QueryBuilder
    {
        if ($columns === null) {
            $columns = $this->pending['fields'];
            if ($columns !== null && $columns !== []) {
                $first = reset($columns);
                $columns = $first instanceof Expression ? $first
                    : SqlList::firstForMode($first, $this->noBackslashEscapes, $this->ansiQuotes);
            }
        } elseif (!is_string($columns) && !($columns instanceof Expression)) {
            throw new \TypeError('Column must be a string, Expression or null');
        }
        return $this->get($tableName, $numRows, $columns);
    }

    /** SELECT 获取某一列第一行的数据 */
    public function getScalar(mixed $tableName, mixed $columns = null): ?QueryBuilder
    {
        return $this->getColumn($tableName, $columns, 1);
    }

    // 写入语句

    /** 插入数据 */
    public function insert(mixed $tableName, mixed $insertData): QueryBuilder
    {
        return $this->compileWrite('INSERT', $tableName, $insertData);
    }

    /** 构造使用多个 VALUES 分组的批量插入语句。 */
    public function insertAll(mixed $tableName, mixed $insertData, mixed $option = []): QueryBuilder
    {
        $batch = WriteCompiler::batchOptions($option, $this->pending['fields']);
        return $this->compileWrite($batch['statement'], $tableName, $insertData, batch: true, fields: $batch['fields']);
    }

    /** 依据表的主键或唯一键约束执行批量插入或更新。 */
    public function upsert(mixed $tableName, array $rows, array $updateColumns): QueryBuilder
    {
        if ($updateColumns === []) { throw new \InvalidArgumentException('Upsert requires update columns'); }
        $previousUpdateColumns = $this->pending['updateColumns'];
        try {
            $this->onDuplicate($updateColumns);
            return $this->insertAll($tableName, $rows);
        } catch (\Throwable $error) {
            // 批量写入失败时恢复调用前的设置，避免影响下一次插入。
            $this->pending['updateColumns'] = $previousUpdateColumns;
            throw $error;
        }
    }

    /** REPLACE插入 */
    public function replace(mixed $tableName, mixed $insertData): QueryBuilder
    {
        return $this->compileWrite('REPLACE', $tableName, $insertData);
    }

    /** 设置插入时的重复键更新（onDuplicate）。 */
    public function onDuplicate(mixed $updateColumns): QueryBuilder
    {
        if ($updateColumns !== null && !is_array($updateColumns)) {
            throw new \TypeError('Duplicate update columns must be an array or null');
        }
        $this->pending['updateColumns'] = $updateColumns;
        return $this;
    }

    /** UPDATE 更新查询。 */
    public function update(mixed $tableName, mixed $tableData, mixed $numRows = null): ?QueryBuilder
    {
        return $this->compileWrite('UPDATE', $tableName, $tableData, $numRows);
    }

    /** DELETE 删除查询。 */
    public function delete(mixed $tableName, mixed $numRows = null): ?QueryBuilder
    {
        return $this->compileWrite('DELETE', $tableName, numRows: $numRows);
    }

    // 状态重置与表达式工厂

    /** 重置构造器 */
    public function reset(): QueryBuilder
    {
        $this->resetBuildState();
        return $this;
    }

    private function resetBuildState(): void
    {
        if ($this->readyToPublish !== null) {
            $this->compiled = $this->readyToPublish;
            $this->readyToPublish = null;
        }
        $this->pending = PendingQuery::DEFAULTS;
    }

    public static function rawExpression(string $sql, array $parameters = []): Expression
    {
        return new Expression($sql, $parameters);
    }

    /** MySQL 函数及当前时间表达式的快捷方法。 */
    public static function now(mixed $diff = null, mixed $func = "NOW()"): array
    {
        return ExpressionFactory::now($diff, $func);
    }

    /** MySQL 递增表达式。 */
    public static function inc(mixed $num = 1): array
    {
        return ExpressionFactory::inc($num);
    }

    /** MySQL 递减表达式。 */
    public static function dec(mixed $num = 1): array
    {
        return ExpressionFactory::dec($num);
    }

    /** MySQL 逻辑取反表达式。 */
    public static function not(mixed $col = null): array
    {
        return ExpressionFactory::not($col);
    }

    /** MySQL 函数表达式。 */
    public static function func(mixed $expr, mixed $bindParams = null): array
    {
        return ExpressionFactory::func($expr, $bindParams);
    }

    /** 创建一个子查询 */
    public static function subQuery(string|null $subQueryAlias = null): QueryBuilder
    {
        return new static(true, $subQueryAlias);
    }

    // 构建结果

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

    /** 按需生成最近一次构建的调试 SQL。 */
    public function getLastQuery(): mixed
    {
        $query = $this->lastBuilt;
        if ($query === null || $query->parameters === []) {
            return $query?->sql;
        }
        return SqlFormatter::renderForMode($query->sql, $query->parameters,
            ...$query->quoteMode);
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
        if (!is_string($sql)) {
            throw new \TypeError('Raw SQL must be a string');
        }
        $parameters = array_map(static fn($value) => $value, array_values($param));
        $quoteMode = $this->countCompilation?->active ? $this->countCompilation->quoteMode : null;
        return $this->completeBuild($sql, $parameters, $this->pending['transactionOp'], quoteMode: $quoteMode);
    }

    // 条件构建与选项处理

    private function conditionConjunction(ConditionConjunction|string $cond, bool $strict = true): string
    {
        if ($cond instanceof ConditionConjunction) { return $cond->value; }
        // 旧 WHERE/HAVING/ON 字符串保留原有拼写及可扩展的 SQL 语法。
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
        // 普通条件也接受 LIKE、IN、BETWEEN 等已有 SQL 运算符。
        if (!$strict) { return $operator; }
        $comparison = ComparisonOperator::tryFrom(strtoupper(trim($operator)));
        if ($comparison === null) {
            throw new \InvalidArgumentException('Unsupported column comparison operator');
        }
        return $comparison->value;
    }

    private function addRawCondition(array &$conditions, string $sql, array $parameters, ConditionConjunction|string $cond): QueryBuilder
    {
        $concat = $this->conditionConjunction($cond);
        $expression = new Expression($sql, $parameters);
        $conditions[] = ['expression' => $expression, 'concat' => $conditions === [] ? '' : $concat];
        return $this;
    }

    private function addColumnCondition(array &$conditions, string $first, string $second, ComparisonOperator|string $operator, ConditionConjunction|string $cond, bool $on = false): QueryBuilder
    {
        $operator = $this->comparisonOperator($operator, true);
        $concat = $this->conditionConjunction($cond);
        $conditions[] = ['column' => [$first, $operator, $second], 'concat' => !$on && $conditions === [] ? '' : $concat];
        return $this;
    }

    private function validateConditionSubquery(array|QueryBuilder $value): void
    {
        if ($value instanceof QueryBuilder && (!$value->isSubQuery() || $value->getLastPrepareQuery() === null)) {
            throw new \InvalidArgumentException('Use a built QueryBuilder::subQuery() for condition subqueries');
        }
    }

    private function addExistsCondition(QueryBuilder $subquery, string $operator, ConditionConjunction|string $cond): QueryBuilder
    {
        $this->validateConditionSubquery($subquery);
        $alias = $subquery->getSubQuery()['alias'];
        if ($alias !== null && $alias !== '') {
            throw new \InvalidArgumentException('EXISTS subqueries must not have an alias');
        }
        $cond = $this->conditionConjunction($cond, false);
        $this->pending['whereConditions'][] = ['exists' => $subquery, 'operator' => $operator, 'concat' => $this->pending['whereConditions'] === [] ? '' : $cond];
        return $this;
    }

    private function addWhereGroup(callable $callback, string $concat): QueryBuilder
    {
        return $this->addConditionGroup($this->pending['whereConditions'], $callback, $concat, 'whereConditions', 'WHERE');
    }

    private function addJoinGroup(string $table, callable $callback, string $concat): QueryBuilder
    {
        $conditions = $this->pending['joinConditions'][$table] ?? [];
        $this->addConditionGroup($conditions, $callback, $concat, 'whereConditions', 'ON', true);
        $this->pending['joinConditions'][$table] = $conditions;
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

    private function lockWaitOption(LockWaitPolicy|string $option): string
    {
        if ($option instanceof LockWaitPolicy) { return $option->value; }
        // 保留旧 SQL 尾部片段（例如 OF table_name）；仅规范化已知策略。
        return LockWaitPolicy::tryFrom(strtoupper(trim($option)))?->value ?? $option;
    }

    // 语句编译与结果发布

    /** @param 'INSERT'|'REPLACE'|'UPDATE'|'DELETE' $statement */
    private function compileWrite(
        string $statement,
        mixed $tableName,
        mixed $tableData = null,
        mixed $numRows = null,
        bool $batch = false,
        ?array $fields = null,
    ): QueryBuilder {
        if ($this->isSubQuery) {
            $operation = $statement === 'INSERT' || $statement === 'REPLACE' ? 'INSERT or REPLACE' : $statement;
            throw new \LogicException('A subquery builder cannot build ' . $operation);
        }
        $this->readyToPublish = null;
        if ($statement !== 'DELETE') {
            $this->pending['tableName'] = $tableName;
        }
        $reference = TableReference::resolve($tableName, $this->prefix);
        $this->pending['tableName'] = $reference['name'];
        $buffer = QueryCompiler::writeHead($statement, $reference, $this->pending['queryOptions'], $this->pending['joins'] !== []);
        return $this->compilePending($buffer, $statement, $numRows ?? $this->pending['limit'], $tableData, $batch, $fields);
    }

    private function compilePending(
        SqlBuffer $buffer,
        string $statement,
        mixed $numRows,
        mixed $tableData = null,
        bool $batch = false,
        ?array $fields = null
    ): QueryBuilder {
        return $this->publishBuild($this->compileQuery($this->pending, $buffer, $statement, $numRows, $tableData, $batch, $fields));
    }

    /** @param State $state */
    private function compileQuery(
        array $state,
        SqlBuffer $buffer,
        string $statement,
        mixed $numRows,
        mixed $tableData = null,
        bool $batch = false,
        ?array $fields = null
    ): CompiledQuery {
        $parts = PendingQuery::parts($state);
        $operation = (new QueryCompiler())->compile(
            $buffer, $parts, $statement, $numRows, $tableData, $fields ?? $state['fields'], $batch
        );
        return CompiledQuery::fromBuffer($buffer, $state['queryOptions'], $operation,
            [$this->noBackslashEscapes, $this->ansiQuotes]);
    }

    /** @param array{bool, bool}|null $quoteMode */
    private function completeBuild(string $sql, array $parameters, ?int $operation, ?array $quoteMode = null): QueryBuilder
    {
        $query = new CompiledQuery($sql, $parameters, $this->pending['queryOptions'], $operation,
            quoteMode: $quoteMode ?? [$this->noBackslashEscapes, $this->ansiQuotes]);
        return $this->publishBuild($query);
    }

    private function publishBuild(CompiledQuery $query): QueryBuilder
    {
        $this->lastBuilt = $query;
        $this->readyToPublish = $query;
        if ($this->countCompilation?->active && $this->countCompilation->skipResetHook) {
            $this->resetBuildState();
        } else {
            $this->reset();
        }
        return $this;
    }

}
