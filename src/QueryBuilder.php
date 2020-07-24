<?php

namespace EasySwoole\Mysqli;

use EasySwoole\Mysqli\Exception\Exception;

/**
 * 查询构造器
 * TODO 支持使用distinct(true)筛选唯一结果
 * Class QueryBuilder
 * @package EasySwoole\Mysqli
 */
class QueryBuilder
{
    private $prefix = '';

    /*
     * 构造结果
     */
    private $lastPrepareQuery = null;
    private $lastBindParams = [];
    private $lastQueryOptions = [];
    private $lastQuery;


    // 以下为查询条件容器
    private $_join = [];
    private $_where = [];
    private $_union = [];
    private $_joinAnd = [];
    private $_having = [];
    private $_orderBy = [];
    private $_groupBy = [];
    private $_queryOptions = [];

    // 以下为查询配置项
    private $_tableLockMethod = "READ";
    private $_bindParams = [''];
    private $_isSubQuery = false;
    private $_updateColumns = null;
    private $_nestJoin = false;
    private $_tableName = '';
    private $_forUpdate = false;
    private $_lockInShareMode = false;
    private $_subQueryAlias = '';
    private $_limit = null;
    private $_field = '*';
    private $_lastInsertId = null;
    /*
     * 查询构造结构
     */
    private $_query;

    public function __construct(bool $isSubQuery = false, ?string $subQueryAlias = null)
    {
        if ($isSubQuery) {
            $this->_isSubQuery = true;
            $this->_subQueryAlias = $subQueryAlias;
        }
    }

    public function limit(int $one, ?int $two = null): QueryBuilder
    {
        if ($two !== null) {
            $this->_limit = [$one, $two];
        } else {
            $this->_limit = $one;
        }
        return $this;
    }

    public function fields($fields): QueryBuilder
    {
        if (!is_array($fields)) {
            $fields = [$fields];
        }
        $this->_field = $fields;
        return $this;
    }

    /**
     * @return array
     */
    public function getField(): array
    {
        if ( !is_array($this->_field) ){
            $field = [$this->_field];
        }else{
            $field = $this->_field;
        }
        return $field;
    }


    /**
     * Where条件
     * TODO Where支持数组条件查询(索引数组和kv数组两种方式)
     * TODO Where支持字符串条件，支持字符串直接预绑定(在Where条件上直接绑定参数)
     * TODO Where支持快捷查询方法(whereLike/whereIn/whereNotIn等)
     * @param $whereProp
     * @param string $whereValue
     * @param string $operator
     * @param string $cond
     * @return $this
     */
    public function where($whereProp, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND')
    {
        if (count($this->_where) == 0) {
            $cond = '';
        }
        $this->_where[] = [$cond, $whereProp, $operator, $whereValue];
        return $this;
    }

    /**
     * WhereOr条件
     * @param $whereProp
     * @param string $whereValue
     * @param string $operator
     * @return QueryBuilder
     */
    public function orWhere($whereProp, $whereValue = 'DBNULL', $operator = '=')
    {
        return $this->where($whereProp, $whereValue, $operator, 'OR');
    }

    /**
     * OrderBy条件
     * @param $orderByField
     * @param string $orderbyDirection
     * @param null $customFieldsOrRegExp
     * @return $this
     * @throws Exception
     */
    public function orderBy($orderByField, $orderbyDirection = "DESC", $customFieldsOrRegExp = null)
    {
        $allowedDirection = ["ASC", "DESC"];
        $orderbyDirection = strtoupper(trim($orderbyDirection));
        $orderByField = preg_replace("/[^ -a-z0-9\.\(\),_`\*\'\"%`]+/i", '', $orderByField);
        // Add table prefix to orderByField if needed.
        //FIXME: We are adding prefix only if table is enclosed into `` to distinguish aliases
        // from table names
        $orderByField = preg_replace('/(\`)([`a-zA-Z0-9_%`\']*\.)/', '\1' . $this->prefix . '\2', $orderByField);
        if (empty($orderbyDirection) || !in_array($orderbyDirection, $allowedDirection)) {
            throw new Exception('Wrong order direction: ' . $orderbyDirection);
        }
        if (is_array($customFieldsOrRegExp)) {
            foreach ($customFieldsOrRegExp as $key => $value) {
                $customFieldsOrRegExp[$key] = preg_replace("/[^\x80-\xff-a-z0-9\.\(\),_` %\']+/i", '', $value);
            }
            $orderByField = 'FIELD (' . $orderByField . ', "' . implode('","', $customFieldsOrRegExp) . '")';
        } elseif (is_string($customFieldsOrRegExp)) {
            $orderByField = $orderByField . " REGEXP '" . $customFieldsOrRegExp . "'";
        } elseif ($customFieldsOrRegExp !== null) {
            throw new Exception('Wrong custom field or Regular Expression: ' . $customFieldsOrRegExp);
        }
        $this->_orderBy[$orderByField] = $orderbyDirection;
        return $this;
    }

    /**
     * GroupBy条件
     * TODO GroupBy数组条件支持
     * @param $groupByField
     * @return $this
     */
    public function groupBy($groupByField)
    {
        $groupByField = preg_replace("/[^-a-z0-9\.\(\),_\* <>=!%`\']+/i", '', $groupByField);
        $this->_groupBy[] = $groupByField;
        return $this;
    }

    /**
     * Having条件
     * TODO Having支持字符串原语查询
     * @param $havingProp
     * @param string $havingValue
     * @param string $operator
     * @param string $cond
     * @return $this
     */
    public function having($havingProp, $havingValue = 'DBNULL', $operator = '=', $cond = 'AND')
    {
        if (is_array($havingValue) && ($key = key($havingValue)) != "0") {
            $operator = $key;
            $havingValue = $havingValue[$key];
        }
        if (count($this->_having) == 0) {
            $cond = '';
        }
        $this->_having[] = [$cond, $havingProp, $operator, $havingValue];
        return $this;
    }

    /**
     * OrHaving条件
     * @param $havingProp
     * @param null $havingValue
     * @param null $operator
     * @return QueryBuilder
     */
    public function orHaving($havingProp, $havingValue = null, $operator = null)
    {
        return $this->having($havingProp, $havingValue, $operator, 'OR');
    }

    /**
     * Join查询
     * TODO Join支持数组传入多个查询条件
     * @param $joinTable
     * @param $joinCondition
     * @param string $joinType
     * @return $this
     * @throws Exception
     */
    public function join($joinTable, $joinCondition, $joinType = '')
    {
        $allowedTypes = ['LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER', 'NATURAL'];
        $joinType = strtoupper(trim($joinType));
        if ($joinType && !in_array($joinType, $allowedTypes)) {
            throw new Exception('Wrong JOIN type: ' . $joinType);
        }
        if (!is_object($joinTable)) {
            $joinTable = $this->prefix . $joinTable;
        }
        $this->_join[] = [$joinType, $joinTable, $joinCondition];
        return $this;
    }

    /**
     * Join xx where xx
     * @param $whereJoin
     * @param $whereProp
     * @param string $whereValue
     * @param string $operator
     * @param string $cond
     * @return $this
     */
    public function joinWhere($whereJoin, $whereProp, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND')
    {
        $this->_joinAnd[$this->prefix . $whereJoin][] = Array($cond, $whereProp, $operator, $whereValue);
        return $this;
    }

    /**
     * Join xx or where xx
     * @param $whereJoin
     * @param $whereProp
     * @param string $whereValue
     * @param string $operator
     * @param string $cond
     * @return QueryBuilder
     */
    public function joinOrWhere($whereJoin, $whereProp, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND')
    {
        return $this->joinWhere($whereJoin, $whereProp, $whereValue, $operator, 'OR');
    }

    /**
     * 构建UNION语句
     * @param $cond
     * @param $isUnionAll
     * @return  $this
     */
    public function union($cond, $isUnionAll = false)
    {
        if ($cond instanceof QueryBuilder) {
            $this->_union[] = [$cond->getLastPrepareQuery(), $cond->getLastBindParams(), $isUnionAll];
        } elseif (is_string($cond)) {
            $this->_union[] = [$cond, [], $isUnionAll];
        }
        return $this;
    }

    /**
     * LockInShareModel锁定(InnoDb)
     * @param bool $isLock
     * @return QueryBuilder
     * @throws Exception
     */
    public function lockInShareMode($isLock = true)
    {
        if ($isLock) {
            $this->setQueryOption(['LOCK IN SHARE MODE']);
        } else {
            unset($this->_queryOptions['LOCK IN SHARE MODE']);
        }
        return $this;
    }

    /**
     * SELECT FOR UPDATE锁定(InnoDb)
     * @param bool $isLock
     * @return QueryBuilder
     * @throws Exception
     */
    public function selectForUpdate($isLock = true)
    {
        if ($isLock) {
            $this->setQueryOption(['FOR UPDATE']);
        } else {
            unset($this->_queryOptions['FOR UPDATE']);
        }
        return $this;
    }

    /**
     * 锁表模式(读/写)
     * @param $method
     * @return $this
     * @throws Exception
     */
    public function setLockTableMode($method)
    {
        switch (strtoupper($method)) {
            case "READ" || "WRITE":
                $this->_tableLockMethod = $method;
                break;
            default:
                throw new Exception("Bad lock type: Can be either READ or WRITE");
                break;
        }
        return $this;
    }

    /**
     * 获得表锁
     * @param $table
     * @return $this
     */
    public function lockTable($table)
    {
        // Main Query
        $this->_query = "LOCK TABLES";
        // Is the table an array?
        if (gettype($table) == "array") {
            // Loop trough it and attach it to the query
            foreach ($table as $key => $value) {
                if (gettype($value) == "string") {
                    if ($key > 0) {
                        $this->_query .= ",";
                    }
                    $this->_query .= " " . $this->prefix . $value . " " . $this->_tableLockMethod;
                    $this->lastQuery = $this->_query;
                }
            }
        } else {
            // Build the table prefix
            $table = $this->prefix . $table;
            // Build the query
            $this->_query = "LOCK TABLES " . $table . " " . $this->_tableLockMethod;
            $this->lastQuery = $this->_query;
        }
        $this->reset();
        return $this;
    }

    /**
     * 释放表锁
     * @return $this
     */
    public function unlockTable()
    {
        $this->_query = "UNLOCK TABLES";
        $this->lastQuery = $this->_query;
        $this->reset();
        return $this;
    }

    /**
     * 设置查询条件
     * @param $options
     * @return $this
     * @throws Exception
     */
    public function setQueryOption($options)
    {
        $allowedOptions = ['ALL', 'DISTINCT', 'DISTINCTROW', 'HIGH_PRIORITY', 'STRAIGHT_JOIN', 'SQL_SMALL_RESULT',
            'SQL_BIG_RESULT', 'SQL_BUFFER_RESULT', 'SQL_CACHE', 'SQL_NO_CACHE', 'SQL_CALC_FOUND_ROWS',
            'LOW_PRIORITY', 'IGNORE', 'QUICK', 'MYSQLI_NESTJOIN', 'FOR UPDATE', 'LOCK IN SHARE MODE'];
        if (!is_array($options)) {
            $options = [$options];
        }
        foreach ($options as $option) {
            $option = strtoupper($option);
            if (!in_array($option, $allowedOptions)) {
                throw new Exception('Wrong query option: ' . $option);
            }
            if ($option == 'MYSQLI_NESTJOIN') {
                $this->_nestJoin = true;
            } elseif ($option == 'FOR UPDATE') {
                $this->_forUpdate = true;
            } elseif ($option == 'LOCK IN SHARE MODE') {
                $this->_lockInShareMode = true;
            } else {
                $this->_queryOptions[] = $option;
            }
        }
        return $this;
    }

    /**
     * 设置表前缀
     * @param string $prefix
     * @return $this
     */
    public function setPrefix($prefix = '')
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * 统计结果行数
     * @return QueryBuilder
     * @throws Exception
     */
    public function withTotalCount(): QueryBuilder
    {
        $this->setQueryOption('SQL_CALC_FOUND_ROWS');
        return $this;
    }

    //---------- 查询方法 ---------- //

    /**
     * SELECT查询
     * @param $tableName
     * @param array|int|null $numRows
     * @param string $columns
     * @return QueryBuilder|null
     */
    public function get($tableName, $numRows = null, $columns = null): ?QueryBuilder
    {
        if ($columns == null) {
            $columns = $this->_field;
        }
        $column = is_array($columns) ? implode(', ', $columns) : $columns;

        $this->_tableName = $tableName;
        $this->_query = 'SELECT ' . implode(' ', $this->_queryOptions) . ' ' .
            $column . " FROM " . $this->_paserTableName();
        if($numRows == null){
            $numRows = $this->_limit;
        }
        $this->_buildQuery($numRows);
        $this->reset();
        return $this;
    }

    /**
     * SELECT LIMIT1查询
     * @param $tableName
     * @param string $columns
     * @return QueryBuilder|null
     */
    public function getOne($tableName, $columns = '*'): ?QueryBuilder
    {
        if ($columns === '*'){
            $columns = $this->_field;
        }
        return $this->get($tableName, 1, $columns);
    }

    /**
     * SELECT 获取某一列的数据
     * @param $tableName
     * @param string $columns
     * @param array|int|null $numRows
     * @return QueryBuilder|null
     */
    public function getColumn($tableName, $columns = null, $numRows = null): ?QueryBuilder
    {
        if (!is_string($columns)) {
            $columns = $this->_field;
            if (is_array($columns) && count($columns) > 0) {
                $columns = explode(',', str_replace(' ', '', reset($columns)))[0];
            }
        }
        return $this->get($tableName, $numRows, $columns);
    }

    /**
     * SELECT 获取某一列第一行的数据
     * @param $tableName
     * @param string $columns
     * @return QueryBuilder|null
     */
    public function getScalar($tableName, $columns = null): ?QueryBuilder
    {
        return $this->getColumn($tableName, $columns, 1);
    }

    /**
     * 插入数据
     * @param $tableName
     * @param $insertData
     * @return $this
     */
    public function insert($tableName, $insertData)
    {
        if (is_array($this->_field)) {
            foreach ($insertData as $key => $val) {
                if (!in_array($key, $this->_field)) {
                    unset($insertData[$key]);
                }
            }
        }
        $this->_buildInsert($tableName, $insertData, 'INSERT');
        $this->reset();
        return $this;
    }
    //多行插入为INSERT INTO ... VALUES (...) , (...)
    public function insertAll($tableName, $insertData, $option = [])
    {
        $allowFields = $option['field'] ?? [];
		// 过滤掉不允许的字段
		if (!empty($allowFields)) {
			foreach ($insertData as $key => $data){
				foreach ($data as $data_k => $data_v){
					if (!in_array($data_k, $allowFields)){
						unset($insertData[$key][$data_k]);
					}
				}
			}
        }

        $this->_buildInsert($tableName, $insertData, isset($option['replace']) ? 'REPLACE' : 'INSERT');
        $this->reset();
        return $this;
    }

    /**
     * REPLACE插入
     * @param $tableName
     * @param $insertData
     * @return $this
     */
    public function replace($tableName, $insertData)
    {
        $this->_buildInsert($tableName, $insertData, 'REPLACE');
        $this->reset();
        return $this;
    }

    /**
     * onDuplicate插入
     * @param $updateColumns
     * @param null $lastInsertId
     * @return $this
     */
    public function onDuplicate($updateColumns, $lastInsertId = null)
    {
        $this->_lastInsertId = $lastInsertId;
        $this->_updateColumns = $updateColumns;
        return $this;
    }

    /**
     * update查询
     * @param $tableName
     * @param $tableData
     * @param array|int|null $numRows
     * @return $this|void
     */
    public function update($tableName, $tableData, $numRows = null)
    {
        if ($this->_isSubQuery) {
            return;
        }

        $this->_tableName = $tableName;
        $this->_query = "UPDATE " . $this->_paserTableName();
        if (is_array($this->_field)) {
            foreach ($tableData as $key => $val) {
                if (!in_array($key, $this->_field)) {
                    unset($tableData[$key]);
                }
            }
        }
        $this->_buildQuery($numRows, $tableData);
        $this->reset();
        return $this;
    }

    /**
     * 插入多行数据
     * @param string $tableName 插入的表名称
     * @param array $multiInsertData 需要插入的数据
     * @param array|null $dataKeys 插入数据对应的字段名
     * @return array|bool
     */
    public function insertMulti($tableName, array $multiInsertData, array $dataKeys = null)
    {
        $ids = array();
        foreach ($multiInsertData as $insertData) {
            if ($dataKeys !== null) {
                // apply column-names if given, else assume they're already given in the data
                $insertData = array_combine($dataKeys, $insertData);
            }
            $id = $this->insert($tableName, $insertData);
            if (!$id) {
                return false;
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * delete查询
     * @param $tableName
     * @param array|int|null $numRows
     * @return $this|void
     */
    public function delete($tableName, $numRows = null)
    {
        if ($this->_isSubQuery) {
            return;
        }
        $this->_tableName = $tableName;
        $table = $this->_paserTableName();
        if (count($this->_join)) {
            $this->_query = "DELETE " . preg_replace('/.* (.*)/', '$1', $table) . " FROM " . $table;
        } else {
            $this->_query = "DELETE FROM " . $table;
        }
        $this->_buildQuery($numRows);
        $this->reset();
        return $this;
    }

    //---------- 辅助方法 ---------- //

    /**
     * 重置构造器
     * @return $this
     */
    public function reset()
    {
        $this->lastPrepareQuery = $this->_query;
        $this->lastBindParams = $this->_bindParams;
        array_shift($this->lastBindParams);
        $this->lastQueryOptions = $this->_queryOptions;
        $this->_limit = null;
        $this->_field = '*';
        $this->_where = [];
        $this->_having = [];
        $this->_join = [];
        $this->_joinAnd = [];
        $this->_orderBy = [];
        $this->_groupBy = [];
        $this->_bindParams = [''];
        $this->_query = null;
        $this->_queryOptions = array();
        $this->_nestJoin = false;
        $this->_forUpdate = false;
        $this->_lockInShareMode = false;
        $this->_tableName = '';
        $this->_updateColumns = null;
        return $this;
    }

    /**
     * MysqlFunc/Now 快捷方法
     * @param null $diff
     * @param string $func
     * @return array
     * @throws Exception
     */
    public static function now($diff = null, $func = "NOW()")
    {
        return array("[F]" => Array(self::interval($diff, $func)));
    }

    /**
     * MysqlInc表达式
     * @param int $num
     * @return array
     * @throws Exception
     */
    public static function inc($num = 1)
    {
        if (!is_numeric($num)) {
            throw new Exception('Argument supplied to inc must be a number');
        }
        return array("[I]" => "+" . $num);
    }

    /**
     * MysqlDec表达式
     * @param int $num
     * @return array
     * @throws Exception
     */
    public static function dec($num = 1)
    {
        if (!is_numeric($num)) {
            throw new Exception('Argument supplied to dec must be a number');
        }
        return array("[I]" => "-" . $num);
    }

    /**
     * MysqlNot表达式
     * @param null $col
     * @return array
     */
    public static function not($col = null)
    {
        return array("[N]" => (string)$col);
    }

    /**
     * MysqlFunc表达式
     * @param $expr
     * @param null $bindParams
     * @return array
     */
    public static function func($expr, $bindParams = null)
    {
        return array("[F]" => array($expr, $bindParams));
    }

    /**
     * 创建一个子查询
     * @param string|null $subQueryAlias
     * @return QueryBuilder
     */
    public static function subQuery(string $subQueryAlias = null)
    {
        return new static(true, $subQueryAlias);
    }

    //---------- 输出方法 ---------- //

    /**
     * 获取构建的SQL
     * @return string|null
     */
    public function getLastPrepareQuery(): ?string
    {
        return $this->lastPrepareQuery;
    }

    /**
     * 获取最后绑定的参数
     * @return array
     */
    public function getLastBindParams()
    {
        return $this->lastBindParams;
    }

    /**
     * 获取最后的查询参数
     * @return array
     */
    function getLastQueryOptions(): array
    {
        return $this->lastQueryOptions;
    }

    function getQueryOptions():array
    {
        return $this->_queryOptions;
    }

    /**
     * 获取构建的SQL(已替换占位符)
     * @return mixed
     */
    public function getLastQuery()
    {
        return $this->lastQuery;
    }

    /**
     * 获取子查询
     * @return array|null
     */
    public function getSubQuery()
    {
        if (!$this->_isSubQuery) {
            return null;
        }
        $val = [
            'query'  => $this->lastPrepareQuery,
            'params' => $this->lastBindParams,
            'alias'  => $this->_subQueryAlias
        ];
        $this->reset();
        return $val;
    }

    /**
     * 当前是否子查询
     * @return bool
     */
    public function isSubQuery(): bool
    {
        return $this->_isSubQuery;
    }

    public function startTransaction()
    {
        $this->raw('start transaction');
    }

    public function commit()
    {
        $this->raw('commit');
    }

    public function rollback()
    {
        $this->raw("rollback");
    }

    public function raw($sql, $param = [])
    {
        $this->_query = $sql;
        if (!empty($param) && is_array($param)){
            $this->_bindParams($param);
            $this->lastQuery = $this->replacePlaceHolders($this->_query, $this->_bindParams);
        }else{
            $this->lastQuery = $sql;
        }
        $this->reset();
        return $this;
    }

    /*
     * ***************** 私有方法
     */

    /**
     * PDO绑定类型检测
     * @param $item
     * @return string
     */
    private function _determineType($item)
    {
        switch (gettype($item)) {
            case 'NULL':
            case 'string':
                return 's';
                break;
            case 'boolean':
            case 'integer':
                return 'i';
                break;
            case 'blob':
                return 'b';
                break;
            case 'double':
                return 'd';
                break;
        }
        return '';
    }

    /**
     * 时间周期
     * @param $diff
     * @param string $func
     * @return string
     * @throws Exception
     */
    private static function interval($diff, $func = "NOW()")
    {
        $types = Array("s" => "second", "m" => "minute", "h" => "hour", "d" => "day", "M" => "month", "Y" => "year");
        $incr = '+';
        $items = '';
        $type = 'd';
        if ($diff && preg_match('/([+-]?) ?([0-9]+) ?([a-zA-Z]?)/', $diff, $matches)) {
            if (!empty($matches[1])) {
                $incr = $matches[1];
            }
            if (!empty($matches[2])) {
                $items = $matches[2];
            }
            if (!empty($matches[3])) {
                $type = $matches[3];
            }
            if (!in_array($type, array_keys($types))) {
                throw new Exception("invalid interval type in '{$diff}'");
            }
            $func .= " " . $incr . " interval " . $items . " " . $types[$type] . " ";
        }
        return $func;
    }

    /**
     * OrderBy约束构建
     */
    private function _buildOrderBy()
    {
        if (empty($this->_orderBy)) {
            return;
        }
        $this->_query .= " ORDER BY ";
        foreach ($this->_orderBy as $prop => $value) {
            if (strtolower(str_replace(" ", "", $prop)) == 'rand()') {
                $this->_query .= "rand(), ";
            } else {
                $this->_query .= $prop . " " . $value . ", ";
            }
        }
        $this->_query = rtrim($this->_query, ', ') . " ";
    }

    /**
     * Limit约束构建
     * @param $numRows
     */
    private function _buildLimit($numRows)
    {
        if (!isset($numRows)) {
            return;
        }
        if (is_array($numRows)) {
            $this->_query .= ' LIMIT ' . (int)$numRows[0] . ', ' . (int)$numRows[1];
        } else {
            $this->_query .= ' LIMIT ' . (int)$numRows;
        }
    }

    /**
     * Join约束构建
     */
    private function _buildJoin()
    {
        if (empty ($this->_join))
            return;
        foreach ($this->_join as $data) {
            list ($joinType, $joinTable, $joinCondition) = $data;
            if (is_object($joinTable))
                $joinStr = $this->_buildPair("", $joinTable);
            else
                $joinStr = $joinTable;
            $this->_query .= " " . $joinType . " JOIN " . $joinStr .
                (false !== stripos($joinCondition, 'using') ? " " : " on ")
                . $joinCondition;
            // Add join and query
            if (!empty($this->_joinAnd) && isset($this->_joinAnd[$joinStr])) {
                foreach ($this->_joinAnd[$joinStr] as $join_and_cond) {
                    list ($concat, $varName, $operator, $val) = $join_and_cond;
                    $this->_query .= " " . $concat . " " . $varName;
                    $this->conditionToSql($operator, $val);
                }
            }
        }
    }

    /**
     * 占位符替换
     * @param $str
     * @param $vals
     * @return bool|string
     */
    private function replacePlaceHolders($str, $vals)
    {
        $i = 1;
        $newStr = "";
        if (empty($vals)) {
            return $str;
        }
        while ($pos = strpos($str, "?")) {
            $val = $vals[$i++];
            if (is_object($val)) {
                $val = '[object]';
            }
            if ($val === null) {
                $val = 'NULL';
            }
            if (is_numeric($val)) {
                $newStr .= substr($str, 0, $pos) . $val;
            } else {
                $newStr .= substr($str, 0, $pos) . "'" . $val . "'";
            }

            $str = substr($str, $pos + 1);
        }
        $newStr .= $str;
        return $newStr;
    }

    /**
     * 条件值构建
     * @param $operator
     * @param $val
     */
    private function conditionToSql($operator, $val)
    {
        switch (strtolower($operator)) {
            case 'not in':
            case 'in':
                $comparison = ' ' . $operator . ' (';
                if (is_object($val)) {
                    $comparison .= $this->_buildPair("", $val);
                } else {
                    foreach ($val as $v) {
                        $comparison .= ' ?,';
                        $this->_bindParam($v);
                    }
                }
                $this->_query .= rtrim($comparison, ',') . ' ) ';
                break;
            case 'not between':
            case 'between':
                $this->_query .= " $operator ? AND ? ";
                $this->_bindParams($val);
                break;
            case 'not exists':
            case 'exists':
                $this->_query .= $operator . $this->_buildPair("", $val);
                break;
            default:
                if (is_array($val))
                    $this->_bindParams($val);
                else if ($val === null)
                    $this->_query .= $operator . " NULL";
                else if ($val != 'DBNULL' || $val == '0')
                    $this->_query .= $this->_buildPair($operator, $val);
        }
    }

    /**
     * 将查询条件构建成语句
     * @param null $numRows
     * @param null $tableData
     */
    private function _buildQuery($numRows = null, $tableData = null)
    {
        $this->_buildJoin();
        $this->_buildInsertQuery($tableData);
        $this->_buildCondition('WHERE', $this->_where);
        $this->_buildGroupBy();
        $this->_buildCondition('HAVING', $this->_having);
        $this->_buildOrderBy();
        $this->_buildLimit($numRows);
        $this->_buildOnDuplicate($tableData);
        $this->_buildUnion();
        if ($this->_forUpdate) {
            $this->_query .= ' FOR UPDATE';
        }
        if ($this->_lockInShareMode) {
            $this->_query .= ' LOCK IN SHARE MODE';
        }
        $this->lastQuery = $this->replacePlaceHolders($this->_query, $this->_bindParams);
    }


    /**
     * 参数绑定
     * @param $value
     */
    private function _bindParam($value)
    {
        $this->_bindParams[0] .= $this->_determineType($value);
        array_push($this->_bindParams, $value);
    }

    /**
     * 多参数绑定
     * @param $values
     */
    private function _bindParams($values)
    {
        foreach ($values as $value) {
            $this->_bindParam($value);
        }
    }

    /**
     * 构建UNION语句
     */
    private function _buildUnion()
    {
        if ($this->_union) {
            foreach ($this->_union as $unionData) {
                list($cond, $param, $isUnionAll) = $unionData;
                $this->_query .= 'UNION ' . ($isUnionAll ? 'ALL ' : '') . $cond;
                $this->_bindParams($param);
            }
        }
    }

    /**
     * 构建占位符
     * @param $operator
     * @param $value
     * @return string
     */
    private function _buildPair($operator, $value)
    {
        if (!is_object($value)) {
            $this->_bindParam($value);
            return ' ' . $operator . ' ? ';
        }
        /** @var QueryBuilder $value */
        $subQuery = $value->getSubQuery();
        $this->_bindParams($subQuery['params']);
        return " " . $operator . " (" . $subQuery['query'] . ") " . $subQuery['alias'];
    }

    /**
     * 构建插入的前半部分
     * @param $tableName
     * @param $insertData
     * @param $operation
     */
    private function _buildInsert($tableName, $insertData, $operation)
    {
        if ($this->_isSubQuery) {
            return;
        }
        $this->_tableName = $tableName;
        $this->_query = $operation . " " . implode(' ', $this->_queryOptions) . " INTO " . $this->_paserTableName();
        $this->_buildQuery(null, $insertData);
    }

    /**
     * 组装插入的值
     * @param $tableData
     * @throws Exception
     */
    private function _buildInsertQuery($tableData)
    {
        if (!is_array($tableData)) {
            return;
        }
        $isInsert = preg_match('/^[INSERT|REPLACE]/', $this->_query);
        // 如果是二维数组则为批量插入
        if (isset($tableData[0]) && is_array($tableData[0])){
            $dataColumns = array_keys($tableData[0]);
            if ($isInsert) {
                if (isset ($dataColumns[0]))
                    $this->_query .= ' (`' . implode('`, `', $dataColumns) . '`) ';
                $this->_query .= ' VALUES ';
                foreach ($tableData as $data){
                    $this->_query .= '(';
                    $this->_buildDataPairs($data, $dataColumns, $isInsert);
                    $this->_query .= '),';
                }
                $this->_query = rtrim($this->_query, ',');
            } else {
                $this->_query .= " SET ";
                $this->_buildDataPairs($tableData, $dataColumns, $isInsert);
            }
        }else{
            $dataColumns = array_keys($tableData);
            if ($isInsert) {
                if (isset ($dataColumns[0]))
                    $this->_query .= ' (`' . implode('`, `', $dataColumns) . '`) ';
                $this->_query .= ' VALUES (';
            } else {
                $this->_query .= " SET ";
            }
            $this->_buildDataPairs($tableData, $dataColumns, $isInsert);
            if ($isInsert) {
                $this->_query .= ')';
            }
        }
    }

    /**
     * 构建OnDuplicate插入
     * @param $tableData
     */
    private function _buildOnDuplicate($tableData)
    {
        if (is_array($this->_updateColumns) && !empty($this->_updateColumns)) {
            $this->_query .= " ON DUPLICATE KEY UPDATE ";
            foreach ($this->_updateColumns as $key => $val) {
                // skip all params without a value
                if (is_numeric($key)) {
                    $this->_updateColumns[$val] = '';
                    unset($this->_updateColumns[$key]);
                } else {
                    $tableData[$key] = $val;
                }
            }
            $this->_buildDataPairs($tableData, array_keys($this->_updateColumns), false);
        }
    }

    /**
     * 处理值绑定
     * @param $tableData
     * @param $tableColumns
     * @param $isInsert
     * @throws Exception
     */
    private function _buildDataPairs($tableData, $tableColumns, $isInsert)
    {
        foreach ($tableColumns as $column) {
            $value = $tableData[$column];
            if (!$isInsert) {
                if (strpos($column, '.') === false) {
                    $this->_query .= "`" . $column . "` = ";
                } else {
                    $this->_query .= str_replace('.', '.`', $column) . "` = ";
                }
            }
            if ($value instanceof QueryBuilder && $value->isSubQuery()) {
                $this->_query .= $this->_buildPair("", $value) . ", ";
                continue;
            }
            // Simple value
            if (!is_array($value)) {
                $this->_bindParam($value);
                $this->_query .= '?, ';
                continue;
            }
            // Function value
            $key = key($value);
            $val = $value[$key];
            switch ($key) {
                case '[I]':
                    $this->_query .= $column . $val . ", ";
                    break;
                case '[F]':
                    $this->_query .= $val[0] . ", ";
                    if (!empty($val[1])) {
                        $this->_bindParams($val[1]);
                    }
                    break;
                case '[N]':
                    if ($val == null) {
                        $this->_query .= "!" . $column . ", ";
                    } else {
                        $this->_query .= "!" . $val . ", ";
                    }
                    break;
                default:
                    throw new Exception("Wrong operation");
            }
        }
        $this->_query = rtrim($this->_query, ', ');
    }

    /**
     * 查询条件构建
     * @param $operator
     * @param $conditions
     */
    private function _buildCondition($operator, &$conditions)
    {
        if (empty($conditions)) {
            return;
        }
        //Prepare the where portion of the query
        $this->_query .= ' ' . $operator;
        foreach ($conditions as $cond) {
            list ($concat, $varName, $operator, $val) = $cond;

             if (strpos($varName, '.') !== false && $val !== 'DBNULL' && strpos($varName, '(') === false){
                // DBNULL是纯字符串条件，也不能有()函数调用
                $varNameArray = explode('.', $varName);
                $varName = "`{$varNameArray[0]}`.`{$varNameArray[1]}`";
            }else{
                // 不是字符串条件，也没有包含()函数调用
                if ($val !== 'DBNULL' && strpos($varName, '(') === false){
                    $varName = "`{$varName}`";
                }
            }

            $this->_query .= " " . $concat . " " . $varName;
            switch (strtolower($operator)) {
                case 'not in':
                case 'in':
                    $comparison = ' ' . $operator . ' (';
                    if (is_object($val)) {
                        $comparison .= $this->_buildPair("", $val);
                    } else {
                        foreach ($val as $v) {
                            $comparison .= ' ?,';
                            $this->_bindParam($v);
                        }
                    }
                    $this->_query .= rtrim($comparison, ',') . ' ) ';
                    break;
                case 'not between':
                case 'between':
                    $this->_query .= " $operator ? AND ? ";
                    $this->_bindParams($val);
                    break;
                case 'not exists':
                case 'exists':
                    $this->_query .= $operator . $this->_buildPair("", $val);
                    break;
                default:
                    if (is_array($val)) {
                        $this->_bindParams($val);
                    } elseif ($val === null) {
                        $this->_query .= ' ' . $operator . " NULL";
                    } elseif ($val != 'DBNULL' || $val == '0') {
                        $this->_query .= $this->_buildPair($operator, $val);
                    }
            }
        }
    }

    /**
     * GroupBy约束构建
     */
    private function _buildGroupBy()
    {
        if (empty($this->_groupBy)) {
            return;
        }
        $this->_query .= " GROUP BY ";
        foreach ($this->_groupBy as $key => $value) {
            $this->_query .= $value . ", ";
        }
        $this->_query = rtrim($this->_query, ', ') . " ";
    }

    /**
     * 表名构造
     * @return string
     */
    private function _paserTableName()
    {
        if (strpos($this->_tableName, '.') === false) {
            $this->_tableName = $this->prefix . $this->_tableName;
        }

        if (strpos($this->_tableName, '`') !== false){
            return $this->_tableName;
        }

        if (strpos($this->_tableName, ' ') !== false){
            return $this->_tableName;
        }

        return "`{$this->_tableName}`";
    }
}
