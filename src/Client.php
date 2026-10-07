<?php


namespace EasySwoole\Mysqli;


use EasySwoole\Mysqli\Exception\Exception;
use mysqli;
use mysqli_result;
use Throwable;

class Client
{
    protected Config $config;

    protected bool $mysqliHasConnected = false;

    protected mysqli|null $mysqlClient = null;

    protected mixed $onQuery = null;

    protected int|string|null $lastInsertId = null;

    protected int|string|null $lastAffectRows = null;

    function __construct(Config $config)
    {
        $this->config = $config;
    }

    function onQuery(callable $call):Client
    {
        $this->onQuery = $call;
        return $this;
    }

    function query(QueryBuilder $builder)
    {
        $this->lastInsertId = null;
        $this->lastAffectRows = null;
        $start = microtime(true);
        $this->connect();

        $lastPrepareSql = $builder->getLastPrepareQuery();

        try {
            $stmt = $this->mysqlClient()->prepare($lastPrepareSql);
        }catch (\Throwable $throwable) {
            throw new Exception("prepare sql {$lastPrepareSql} error , {$throwable->getMessage()}", (int)$throwable->getCode(), $throwable);
        }

        if(!$stmt){
            throw new Exception("mysqli prepare {$lastPrepareSql} fail: {$this->mysqlClient()->error}", $this->mysqlClient()->errno);
        }
        try {
            $params = $builder->getLastBindParams();
            $types = '';
            foreach ($params as $param) {
                $type = $this->determineType($param);
                if ($type === '') {
                    throw new Exception('Unsupported bind parameter type: ' . get_debug_type($param));
                }
                $types .= $type;
            }
            if ($types !== '' && !$stmt->bind_param($types, ...$params)) {
                throw new Exception($stmt->error, $stmt->errno);
            }
            if (!$stmt->execute()) {
                throw new Exception($stmt->error, $stmt->errno);
            }
            $ret = $stmt->get_result();
            if ($ret instanceof mysqli_result) {
                $result = $ret;
                $ret = $result->fetch_all(MYSQLI_ASSOC);
                $result->free();
            } elseif ($stmt->errno) {
                throw new Exception($stmt->error, $stmt->errno);
            } elseif ($stmt->field_count === 0) {
                $ret = true;
            }
            $this->lastInsertId = $stmt->insert_id;
            $this->lastAffectRows = $stmt->affected_rows;
        } catch (\Throwable $throwable) {
            throw new Exception("Sql {$lastPrepareSql} execute error {$throwable->getMessage()}", (int)$throwable->getCode(), $throwable);
        } finally {
            $stmt->close();
        }
        if ($this->onQuery) {
            call_user_func($this->onQuery, $ret, $this, $start);
        }
        return $ret;
    }


    function rawQuery(string $query)
    {
        $this->lastInsertId = null;
        $this->lastAffectRows = null;
        $start = microtime(true);
        $this->connect();
        try {
            $ret = $this->mysqlClient()->query($query);
        }catch (\Throwable $throwable){
            throw new Exception("exec sql {$query} error , {$throwable->getMessage()}", (int)$throwable->getCode(), $throwable);
        }

        if($ret instanceof mysqli_result){
            $result = $ret;
            $ret = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
        if ($ret === false) {
            throw new Exception($this->mysqlClient()->error, $this->mysqlClient()->errno);
        }
        $this->lastInsertId = $this->mysqlClient()->insert_id;
        $this->lastAffectRows = $this->mysqlClient()->affected_rows;
        if($this->onQuery){
            call_user_func($this->onQuery,$ret,$this,$start);
        }
        return $ret;
    }

    function mysqlClient():mysqli|null
    {
        return $this->mysqlClient;
    }

    function connect():bool
    {
        if($this->mysqliHasConnected){
            return true;
        }
        $this->mysqlClient = new mysqli();
        $c = [
            'hostname'=>$this->config->getHost(),
            'username'=>$this->config->getUser(),
            'password'=>$this->config->getPassword(),
            'port'=>$this->config->getPort()
        ];
        $this->mysqlClient->options(MYSQLI_OPT_CONNECT_TIMEOUT,$this->config->getMaxConnectTime());
        try {
            $ret = $this->mysqlClient->connect(...$c);
            if (!$ret || !$this->mysqlClient->select_db($this->config->getDatabase()) ||
                !$this->mysqlClient->set_charset($this->config->getCharset())) {
                throw new Exception($this->mysqlClient->error, $this->mysqlClient->errno);
            }
            $this->mysqliHasConnected = true;
        }catch (\Throwable $throwable){
            throw new Exception("connect to {$this->config->getHost()}:{$this->config->getPort()} error,{$throwable->getMessage()}", (int)$throwable->getCode(), $throwable);
        }
        return $ret;
    }

    function close():bool
    {
        if($this->mysqliHasConnected){
            $this->mysqlClient()->close();
            $this->mysqliHasConnected = false;
        }
        $this->mysqlClient = null;

        return true;
    }

    function __destruct()
    {
        $this->close();
    }

    function ping():bool
    {
        try{
            $result = $this->mysqlClient()->query('select 1');
            if ($result instanceof mysqli_result) {
                $result->free();
                return true;
            }
            return false;
        }catch (\Throwable){
            return false;
        }
    }

    protected function determineType($item)
    {
        switch (gettype($item)) {
            case 'NULL':
            case 'string':
                return 's';

            case 'boolean':
            case 'integer':
                return 'i';

            case 'blob':
                return 'b';

            case 'double':
                return 'd';
        }
        return '';
    }

    public function getLastInsertId(): int|string|null
    {
        return $this->lastInsertId;
    }

    public function getLastAffectRows(): int|string|null
    {
        return $this->lastAffectRows;
    }
}