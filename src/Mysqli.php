<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/7/20
 * Time: 上午11:24
 */

namespace EasySwoole\Mysqli;
use EasySwoole\Mysqli\Exceptions\ConnectFail;
use EasySwoole\Mysqli\Exceptions\JoinFail;
use EasySwoole\Mysqli\Exceptions\Option;
use EasySwoole\Mysqli\Exceptions\OrderByFail;
use EasySwoole\Mysqli\Exceptions\PrepareQueryFail;
use \Swoole\Coroutine\MySQL as CoroutineMySQL;
use \Swoole\Coroutine\MySQL\Statement;


class Mysqli
{
    private $config;//数据库配置项
    private $coroutineMysqlClient;//swoole 协程MYSQL客户端
    /*
     * 以下为ORM构造配置项
     */
    private $where = [];
    private $join = [];
    private $orderBy = [];
    private $groupBy = [];
    private $bindParams = [];
    private $query = null;
    private $queryOptions = [];
    private $having = [];
    private $updateColumns = [];
    private $affectRows = 0;
    private $totalCount = 0;
    private $tableName;
    private $forUpdate = false;
    private $lockInShareMode = false;
    private $queryAllowOptions = ['ALL','DISTINCT','DISTINCTROW','HIGH_PRIORITY','STRAIGHT_JOIN','SQL_SMALL_RESULT',
        'SQL_BIG_RESULT','SQL_BUFFER_RESULT','SQL_CACHE','SQL_NO_CACHE', 'SQL_CALC_FOUND_ROWS',
        'LOW_PRIORITY','IGNORE','QUICK'];

    /*
     * 子查询配置
     */
    private $alias;

    /*
     * 以下为错误或者debug信息
     */
    private $stmtError;
    private $stmtErrno;
    private $lastQuery;
    private $traceEnabled = false;//是否开启调用追踪
    private $trace = [];//追踪调用记录
    private $traceQueryStartTime = null;//语句开始执行时间
    private $lastInsertId;

    /*
     * 事务配置项
     */
    private $startTransaction = false;

    function __construct(Config $config)
    {
        $this->config = $config;
        if(!$this->config->isSubQuery()){
            $this->coroutineMysqlClient = new CoroutineMySQL();
        }
    }

    /*
     * 链接数据库，记住，链接失败的时候，请外部捕获异常
     */
    public function connect()
    {
        if($this->coroutineMysqlClient->connected){
            return true;
        }else{
            try{
                $ret = $this->coroutineMysqlClient->connect($this->config->toArray());
                if($ret){
                    return true;
                }else{
                    throw new ConnectFail("connect to {$this->config->getHost()}@{$this->config->getUser()} at port {$this->config->getPort()} fail");
                }
            }catch (\Throwable $throwable){
                throw new ConnectFail($throwable->getMessage());
            }
        }
    }
    /*
     * 断开数据库链接
     */
    function disConnect()
    {
        $this->coroutineMysqlClient->close();
    }
    /*
     * 获取协程客户端
     */
    function getMysqlClient():CoroutineMySQL
    {
        return $this->coroutineMysqlClient;
    }

    /*
     * 重置数据库状态
     */
    public function resetDbStatus()
    {
        if ($this->traceEnabled)
            $this->trace[] = array ($this->lastQuery, (microtime(true) - $this->traceQueryStartTime) , $this->traceGetCaller());

        $this->where = [];
        $this->join = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->bindParams = [];
        $this->query = null;
        $this->queryOptions = [];
        $this->having = [];
        $this->updateColumns = [];
//        $this->affectRows = 0;此字段不需要重置
//        $this->totalCount = 0;
        $this->tableName;
        $this->forUpdate = false;
        $this->lockInShareMode = false;
    }

    function startTrace()
    {
        $this->traceEnabled = true;
        $this->trace = [];
    }

    function endTrace()
    {
        $this->traceEnabled = true;
        $res = $this->trace;
        $this->trace = [];
        return $res;
    }
    /**
     * @throws \Exception,
     */
    public function rawQuery($query, array $bindParams = [])
    {
        $this->bindParams = $bindParams;
        $this->query = $query;
        $stmt = $this->prepareQuery();
        $res = $this->exec($stmt);
        $this->affectRows = $stmt->affected_rows;
        $this->stmtError = $stmt->error;
        $this->stmtErrno = $stmt->errno;
        $this->lastQuery = $this->replacePlaceHolders($this->query, $bindParams);
        $this->resetDbStatus();
        return $res;
    }


    public function startTransaction():bool
    {
        if($this->startTransaction){
            return true;
        }else{
            $this->connect();
            $res =  $this->coroutineMysqlClient->query('start transaction');
            if($res){
                $this->startTransaction = true;
            }
            return $res;
        }
    }

    public function commit():bool
    {
        if($this->startTransaction){
            $this->connect();
            $res =  $this->coroutineMysqlClient->query('commit');
            if($res){
                $this->startTransaction = false;
            }
            return $res;
        }else{
            return true;
        }
    }

    public function rollback($commit = true)
    {
        if($this->startTransaction){
            $this->connect();
            $res =  $this->coroutineMysqlClient->query('rollback');
            if($res && $commit){
                $res = $this->commit();
                if($res){
                    $this->startTransaction = false;
                }
                return $res;
            }else{
                return $res;
            }
        }else{
            return true;
        }
    }

    public function where($whereProp, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND'):Mysqli
    {
        if (is_array($whereValue) && ($key = key($whereValue)) != "0") {
            $operator = $key;
            $whereValue = $whereValue[$key];
        }
        if (count($this->where) == 0) {
            $cond = '';
        }
        $this->where[] = array($cond, $whereProp, $operator, $whereValue);
        return $this;
    }

    public function orWhere($whereProp, $whereValue = 'DBNULL', $operator = '='):Mysqli
    {
        return $this->where($whereProp, $whereValue, $operator, 'OR');
    }

    /**
     * @throws \Exception,
     */
    public function get($tableName, $numRows = null, $columns = '*')
    {
        $this->tableName = $tableName;
        if (empty($columns)) {
            $columns = '*';
        }
        $column = is_array($columns) ? implode(', ', $columns) : $columns;
        $this->query = 'SELECT ' . implode(' ', $this->queryOptions) . ' ' .
            $column . ' FROM ' . $this->tableName;
        $stmt = $this->buildQuery($numRows);

        if ($this->config->isSubQuery()) {
            return $this;
        }
        $res = $this->exec($stmt);
        $this->stmtError = $stmt->error;
        $this->stmtErrno = $stmt->errno;
        $this->affectRows = $stmt->affected_rows;
        $this->resetDbStatus();
        return $res;
    }

    /**
     * @throws \Exception,
     */
    public function getOne($tableName, $columns = '*')
    {
        $res = $this->get($tableName, 1, $columns);
        if ($res instanceof Mysqli) {
            return $res;
        } elseif (is_array($res) && isset($res[0])) {
            return $res[0];
        } elseif ($res) {
            return $res;
        }
        return null;
    }

    /**
     * @throws \Exception,
     */
    public function insert($tableName, $insertData)
    {
        return $this->buildInsert($tableName, $insertData, 'INSERT');
    }

    /**
     * @throws \Exception,
     */
    public function delete($tableName, $numRows = null)
    {
        if ($this->config->isSubQuery()) {
            return;
        }
        $table =  $tableName;
        if (count($this->join)) {
            $this->query = 'DELETE ' . preg_replace('/.* (.*)/', '$1', $table) . " FROM " . $table;
        } else {
            $this->query = 'DELETE FROM ' . $table;
        }
        $stmt = $this->buildQuery($numRows);
        $this->exec($stmt);
        $this->stmtError = $stmt->error;
        $this->stmtErrno = $stmt->errno;
        $this->resetDbStatus();

        return ($stmt->affected_rows > -1);	//	affected_rows returns 0 if nothing matched where statement, or required updating, -1 if error
    }

    /**
     * @throws \Exception,
     */
    public function update($tableName, $tableData, $numRows = null)
    {
        if ($this->config->isSubQuery()) {
            return;
        }
        $this->query = "UPDATE " . $tableName;

        $stmt = $this->buildQuery($numRows, $tableData);
        $status = $this->exec($stmt);
        $this->resetDbStatus();
        $this->stmtError = $stmt->error;
        $this->stmtErrno = $stmt->errno;
        $this->affectRows = $stmt->affected_rows;

        return $status;
    }

    public function tableExists($tables)
    {
        $tables = !is_array($tables) ? Array($tables) : $tables;
        $count = count($tables);
        if ($count == 0) {
            return false;
        }
        foreach ($tables as $i => $value)
            $tables[$i] =  $value;
        $this->where('table_schema', $this->config->getDatabase());
        $this->where('table_name', $tables, 'in');
        $ret = $this->get('information_schema.tables', $count);
        if(is_array($ret) && $count == count($ret)){
            return true;
        }else{
            return false;
        }
    }

    public function inc(int $num = 1)
    {
        return array("[I]" => "+" . $num);
    }

    public function withTotalCount()
    {
        $this->setQueryOption ('SQL_CALC_FOUND_ROWS');
        return $this;
    }

    /**
     * @return int
     */
    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    /**
     * @return int
     */
    public function getAffectRows(): int
    {
        return $this->affectRows;
    }


    public function dec(int $num = 1)
    {
        return array("[I]" => "-" . $num);
    }

    public function join($joinTable, $joinCondition, $joinType = '')
    {
        $allowedTypes = array('LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER', 'NATURAL');
        $joinType = strtoupper(trim($joinType));
        if ($joinType && !in_array($joinType, $allowedTypes)) {
            throw new JoinFail('Wrong JOIN type: ' . $joinType);
        }
        $this->join[] = Array($joinType, $joinTable, $joinCondition);
        return $this;
    }


    public function setQueryOption ($options)
    {
        if (!is_array ($options)){
            $options = Array ($options);
        }
        foreach ($options as $option) {
            $option = strtoupper ($option);
            if (!in_array ($option, $this->queryAllowOptions)){
                throw new Option('Wrong query option: '.$option);
            }else{
                if(!in_array($option,$this->queryOptions)){
                    $this->queryOptions[] = $option;
                }
            }
        }
        return $this;
    }

    private function buildQuery($numRows = null, $tableData = null)
    {
        $this->buildJoin();
        $this->buildInsertQuery($tableData);
        $this->buildCondition('WHERE', $this->where);
        $this->buildGroupBy();
        $this->buildCondition('HAVING', $this->having);
        $this->buildOrderBy();
        $this->buildLimit($numRows);
        $this->buildOnDuplicate($tableData);

        if ($this->forUpdate) {
            $this->query .= ' FOR UPDATE';
        }
        if ($this->lockInShareMode) {
            $this->query .= ' LOCK IN SHARE MODE';
        }

        $this->lastQuery = $this->replacePlaceHolders($this->query, $this->bindParams);

        if ($this->config->isSubQuery()) {
            return;
        }
        // Prepare query
        $stmt = $this->prepareQuery();
        return $stmt;
    }

    private function exec($stmt)
    {
        if(!$this->coroutineMysqlClient->connected){
            $this->connect();
        }
        if (!empty($this->bindParams)) {
            $data = $this->bindParams;
        }else{
            $data = [];
        }
        $ret =  $stmt->execute($data);
        if (in_array ('SQL_CALC_FOUND_ROWS', $this->queryOptions)) {
            $hitCount = $this->getMysqlClient()->query('SELECT FOUND_ROWS() as count');
            $this->totalCount = $hitCount[0]['count'];
        }
        return $ret;
    }

    private function buildJoin ()
    {
        if (empty ($this->join))
            return;
        foreach ($this->join as $data) {
            list ($joinType,  $joinTable, $joinCondition) = $data;

            if (is_object ($joinTable))
                $joinStr = $this->buildPair ("", $joinTable);
            else
                $joinStr = $joinTable;

            $this->query .= " " . $joinType. " JOIN " . $joinStr .
                (false !== stripos($joinCondition, 'using') ? " " : " on ")
                . $joinCondition;
            // Add join and query
            if (!empty($this->joinAnd) && isset($this->joinAnd[$joinStr])) {
                foreach($this->joinAnd[$joinStr] as $join_and_cond) {
                    list ($concat, $varName, $operator, $val) = $join_and_cond;
                    $this->query .= " " . $concat ." " . $varName;
                    $this->conditionToSql($operator, $val);
                }
            }
        }
    }

    private function buildPair($operator, $value)
    {
        if($value instanceof Mysqli){
            $subQuery = $value->getSubQuery();
            $this->bindParams($subQuery['params']);
            return " " . $operator . " (" . $subQuery['query'] . ") " . $subQuery['alias'];
        }else{
            $this->bindParam($value);
            return ' ' . $operator . ' ? ';
        }
    }

    private function bindParam($value)
    {
        array_push($this->bindParams, $value);
    }

    private function bindParams($values)
    {
        foreach ($values as $value) {
            $this->bindParam($value);
        }
    }

    private function determineType($item)
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

    private function conditionToSql($operator, $val) {
        switch (strtolower ($operator)) {
            case 'not in':
            case 'in':
                $comparison = ' ' . $operator. ' (';
                if (is_object ($val)) {
                    $comparison .= $this->buildPair ("", $val);
                } else {
                    foreach ($val as $v) {
                        $comparison .= ' ?,';
                        $this->bindParam ($v);
                    }
                }
                $this->query .= rtrim($comparison, ',').' ) ';
                break;
            case 'not between':
            case 'between':
                $this->query .= " $operator ? AND ? ";
                $this->bindParams ($val);
                break;
            case 'not exists':
            case 'exists':
                $this->query.= $operator . $this->buildPair ("", $val);
                break;
            default:
                if (is_array ($val))
                    $this->bindParams ($val);
                else if ($val === null)
                    $this->query .= $operator . " NULL";
                else if ($val != 'DBNULL' || $val == '0')
                    $this->query .= $this->buildPair ($operator, $val);
        }
    }

    public function getSubQuery()
    {
        if (!$this->config->isSubQuery()) {
            return null;
        }
        $val = Array('query' => $this->query,
            'params' => $this->bindParams,
            'alias' => $this->alias
        );
        $this->resetDbStatus();
        return $val;
    }

    public function subQuery($subQueryAlias = ""):Mysqli
    {
        $conf = new Config();
        $conf->setIsSubQuery(true);
        $conf->setAlias($subQueryAlias);
        return new self($conf);
    }

    private function buildInsertQuery($tableData)
    {
        if (!is_array($tableData)) {
            return;
        }

        $isInsert = preg_match('/^[INSERT|REPLACE]/', $this->query);
        $dataColumns = array_keys($tableData);
        if ($isInsert) {
            if (isset ($dataColumns[0]))
                $this->query .= ' (`' . implode($dataColumns, '`, `') . '`) ';
            $this->query .= ' VALUES (';
        } else {
            $this->query .= " SET ";
        }

        $this->buildDataPairs($tableData, $dataColumns, $isInsert);

        if ($isInsert) {
            $this->query .= ')';
        }
    }

    private function buildDataPairs($tableData, $tableColumns, $isInsert)
    {
        foreach ($tableColumns as $column) {
            $value = $tableData[$column];

            if (!$isInsert) {
                if(strpos($column,'.') === false) {
                    $this->query .= "`" . $column . "` = ";
                } else {
                    $this->query .= str_replace('.','.`',$column) . "` = ";
                }
            }
            // SubQuery value
            if ($value instanceof Mysqli) {
                $this->query .= $this->buildPair("", $value) . ", ";
                continue;
            }

            // Simple value
            if (!is_array($value)) {
                $this->bindParam($value);
                $this->query .= '?, ';
                continue;
            }

            // Function value
            $key = key($value);
            $val = $value[$key];
            switch ($key) {
                case '[I]':
                    $this->query .= $column . $val . ", ";
                    break;
                case '[F]':
                    $this->query .= $val[0] . ", ";
                    if (!empty($val[1])) {
                        $this->bindParams($val[1]);
                    }
                    break;
                case '[N]':
                    if ($val == null) {
                        $this->query .= "!" . $column . ", ";
                    } else {
                        $this->query .= "!" . $val . ", ";
                    }
                    break;
                default:
                    throw new \Exception("Wrong operation");
            }
        }
        $this->query = rtrim($this->query, ', ');
    }

    private function buildCondition($operator, &$conditions)
    {
        if (empty($conditions)) {
            return;
        }

        $this->query .= ' ' . $operator;

        foreach ($conditions as $cond) {
            list ($concat, $varName, $operator, $val) = $cond;
            $this->query .= " " . $concat . " " . $varName;

            switch (strtolower($operator)) {
                case 'not in':
                case 'in':
                    $comparison = ' ' . $operator . ' (';
                    if (is_object($val)) {
                        $comparison .= $this->buildPair("", $val);
                    } else {
                        foreach ($val as $v) {
                            $comparison .= ' ?,';
                            $this->bindParam($v);
                        }
                    }
                    $this->query .= rtrim($comparison, ',') . ' ) ';
                    break;
                case 'not between':
                case 'between':
                    $this->query .= " $operator ? AND ? ";
                    $this->bindParams($val);
                    break;
                case 'not exists':
                case 'exists':
                    $this->query.= $operator . $this->buildPair("", $val);
                    break;
                default:
                    if (is_array($val)) {
                        $this->bindParams($val);
                    } elseif ($val === null) {
                        $this->query .= ' ' . $operator . " NULL";
                    } elseif ($val != 'DBNULL' || $val == '0') {
                        $this->query .= $this->buildPair($operator, $val);
                    }
            }
        }
    }

    private function buildGroupBy()
    {
        if (empty($this->groupBy)) {
            return;
        }

        $this->query .= " GROUP BY ";

        foreach ($this->groupBy as $key => $value) {
            $this->query .= $value . ", ";
        }

        $this->query = rtrim($this->query, ', ') . " ";
    }

    private function buildOrderBy()
    {
        if (empty($this->orderBy)) {
            return;
        }

        $this->query .= " ORDER BY ";
        foreach ($this->orderBy as $prop => $value) {
            if (strtolower(str_replace(" ", "", $prop)) == 'rand()') {
                $this->query .= "rand(), ";
            } else {
                $this->query .= $prop . " " . $value . ", ";
            }
        }

        $this->query = rtrim($this->query, ', ') . " ";
    }

    private function buildLimit($numRows)
    {
        if (!isset($numRows)) {
            return;
        }

        if (is_array($numRows)) {
            $this->query .= ' LIMIT ' . (int) $numRows[0] . ', ' . (int) $numRows[1];
        } else {
            $this->query .= ' LIMIT ' . (int) $numRows;
        }
    }

    private function buildOnDuplicate($tableData)
    {
        if (is_array($this->updateColumns) && !empty($this->updateColumns)) {
            $this->query .= " ON DUPLICATE KEY UPDATE ";
            if ($this->lastInsertId) {
                $this->query .= $this->lastInsertId . "=LAST_INSERT_ID (" . $this->lastInsertId . "), ";
            }

            foreach ($this->updateColumns as $key => $val) {
                // skip all params without a value
                if (is_numeric($key)) {
                    $this->updateColumns[$val] = '';
                    unset($this->updateColumns[$key]);
                } else {
                    $tableData[$key] = $val;
                }
            }
            $this->buildDataPairs($tableData, array_keys($this->updateColumns), false);
        }
    }

    private function replacePlaceHolders($str, $vals)
    {
        $i = 0;
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
            $newStr .= substr($str, 0, $pos) . "'" . $val . "'";
            $str = substr($str, $pos + 1);
        }
        $newStr .= $str;
        return $newStr;
    }

    private function prepareQuery()
    {

        if(!$this->coroutineMysqlClient->connected){
           $this->connect();
        }
        if ($this->traceEnabled)
            $this->traceQueryStartTime = microtime (true);
        $res = $this->coroutineMysqlClient->prepare($this->query);
        if($res instanceof Statement){
            return $res;
        }
        $error = $this->coroutineMysqlClient->error;
        $query = $this->query;
        $errno = $this->coroutineMysqlClient->errno;
        $this->resetDbStatus();
        throw new PrepareQueryFail(sprintf('%s query: %s', $error, $query), $errno);
    }

    private function refValues(array &$arr)
    {
        if (strnatcmp(phpversion(), '5.3') >= 0) {
            $refs = array();
            foreach ($arr as $key => $value) {
                $refs[$key] = & $arr[$key];
            }
            return $refs;
        }
        return $arr;
    }

    private function buildInsert($tableName, $insertData, $operation)
    {
        if ($this->config->isSubQuery()) {
            return;
        }
        $this->query = $operation . " " . implode(' ', $this->queryOptions) . " INTO " . $tableName;
        $stmt = $this->buildQuery(null, $insertData);
        $status = $this->exec($stmt);
        $this->stmtError = $stmt->error;
        $this->stmtErrno = $stmt->errno;
        $haveOnDuplicate = !empty ($this->updateColumns);
        $this->resetDbStatus();
        $this->affectRows = $stmt->affected_rows;
        if ($stmt->affected_rows < 1) {
            // in case of onDuplicate() usage, if no rows were inserted
            if ($status && $haveOnDuplicate) {
                return true;
            }
            return false;
        }
        if ($stmt->insert_id > 0) {
            return $stmt->insert_id;
        }
        return true;
    }

    public function getInsertId()
    {
        return $this->coroutineMysqlClient->insert_id;
    }

    public function getLastQuery()
    {
        return $this->lastQuery;
    }

    /**
     * Method returns mysql error
     *
     * @return string
     */
    public function getLastError()
    {
        return trim($this->stmtError . " " . $this->coroutineMysqlClient->error);
    }

    public function getLastErrno () {
        return $this->stmtErrno;
    }

    public function orderBy($orderByField, $orderbyDirection = "DESC", $customFieldsOrRegExp = null)
    {
        $allowedDirection = Array("ASC", "DESC");
        $orderbyDirection = strtoupper(trim($orderbyDirection));
        $orderByField = preg_replace("/[^ -a-z0-9\.\(\),_`\*\'\"]+/i", '', $orderByField);

        $orderByField = preg_replace('/(\`)([`a-zA-Z0-9_]*\.)/', '\1' . '\2', $orderByField);

        if (empty($orderbyDirection) || !in_array($orderbyDirection, $allowedDirection)) {
            throw new OrderByFail('Wrong order direction: ' . $orderbyDirection);
        }

        if (is_array($customFieldsOrRegExp)) {
            foreach ($customFieldsOrRegExp as $key => $value) {
                $customFieldsOrRegExp[$key] = preg_replace("/[^-a-z0-9\.\(\),_` ]+/i", '', $value);
            }
            $orderByField = 'FIELD (' . $orderByField . ', "' . implode('","', $customFieldsOrRegExp) . '")';
        }elseif(is_string($customFieldsOrRegExp)){
            $orderByField = $orderByField . " REGEXP '" . $customFieldsOrRegExp . "'";
        }elseif($customFieldsOrRegExp !== null){
            throw new OrderByFail('Wrong custom field or Regular Expression: ' . $customFieldsOrRegExp);
        }

        $this->orderBy[$orderByField] = $orderbyDirection;
        return $this;
    }

    public function groupBy($groupByField)
    {
        $groupByField = preg_replace("/[^-a-z0-9\.\(\),_\* <>=!]+/i", '', $groupByField);

        $this->groupBy[] = $groupByField;
        return $this;
    }

    /*
     * 获取追踪调用
     */
    private function traceGetCaller () {
        $dd = debug_backtrace ();
        $caller = next ($dd);
        while (isset ($caller) &&  $caller["file"] == __FILE__ )
            $caller = next($dd);
        return __CLASS__ . "->" . $caller["function"] . "() >>  file \"" .
            $caller["file"]  . "\" line #" . $caller["line"] . " " ;
    }

    function __destruct()
    {
        // TODO: Implement __destruct() method.
        if($this->coroutineMysqlClient->connected){
            $this->coroutineMysqlClient->close();
        }
        unset($this->coroutineMysqlClient);
    }
}