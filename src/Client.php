<?php


namespace EasySwoole\Mysqli;


use EasySwoole\Mysqli\Exception\Exception;
use Swoole\Coroutine\MySQL;

class Client
{
    protected $config;

    protected $mysqlClient;

    protected $queryBuilder;

    protected $trace = [];
    protected $enableTrace = false;

    function enableTrace():Client
    {
        $this->enableTrace = true;
        return $this;
    }

    function endTrace():array
    {
        $ret = $this->trace;
        $this->trace = [];
        $this->enableTrace = false;
        return $ret;
    }

    function __construct(Config $config)
    {
        $this->config = $config;
        $this->queryBuilder = new QueryBuilder();
    }

    function queryBuilder():QueryBuilder
    {
        return $this->queryBuilder;
    }

    function reset()
    {
        $this->queryBuilder()->reset();
    }

    function execBuilder(float $timeout = null)
    {
        $start = microtime(true);
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        $this->connect();
        $stmt = $this->mysqlClient()->prepare($this->queryBuilder()->getLastPrepareQuery(),$timeout);
        $ret = null;
        if($stmt){
            $ret = $stmt->execute($this->queryBuilder()->getLastBindParams(),$timeout);
        }
        if($this->enableTrace){
            $this->trace[] = [
                'start'=>$start,
                'end'=>microtime(true),
                'sql'=>$this->queryBuilder()->getLastQuery()
            ];
        }
        if($this->mysqlClient()->errno){
            throw new Exception($this->mysqlClient()->error);
        }
        return $ret;
    }

    function rawQuery(string $query,float $timeout = null)
    {
        $start = microtime(true);
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        $this->connect();
        $ret = $this->mysqlClient()->query($query,$timeout);
        if($this->enableTrace){
            $this->trace[] = [
                'start'=>$start,
                'end'=>microtime(true),
                'sql'=>$query
            ];
        }
        if($this->mysqlClient()->errno){
            throw new Exception($this->mysqlClient()->error);
        }
        return $ret;
    }

    function mysqlClient():?MySQL
    {
        return $this->mysqlClient;
    }

    function connect():bool
    {
        if(!$this->mysqlClient instanceof MySQL){
            $this->mysqlClient = new MySQL();
        }
        if(!$this->mysqlClient->connected){
           return (bool)$this->mysqlClient->connect($this->config->toArray());
        }
        return true;
    }

    function close():bool
    {
        if($this->mysqlClient instanceof MySQL && $this->mysqlClient->connected){
            $this->mysqlClient->close();
            $this->mysqlClient = null;
        }
        return true;
    }

    function __destruct()
    {
        $this->close();
    }
}