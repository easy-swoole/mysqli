<?php


namespace EasySwoole\Mysqli;


use EasySwoole\Mysqli\Exception\Exception;
use Swoole\Coroutine\MySQL;

class Client
{
    protected $config;

    protected $mysqlClient;

    protected $queryBuilder;

    protected $lastQueryBuilder;

    protected $onQuery;

    function __construct(Config $config)
    {
        $this->config = $config;
        $this->queryBuilder = new QueryBuilder();
    }

    function onQuery(callable $call):Client
    {
        $this->onQuery = $call;
        return $this;
    }

    function queryBuilder():QueryBuilder
    {
        return $this->queryBuilder;
    }

    function lastQueryBuilder():?QueryBuilder
    {
        return $this->lastQueryBuilder;
    }

    function reset()
    {
        $this->queryBuilder()->reset();
    }

    function execBuilder(float $timeout = null)
    {
        $this->lastQueryBuilder = $this->queryBuilder;
        $start = microtime(true);
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        try{
            $this->connect();
            $stmt = $this->mysqlClient()->prepare($this->queryBuilder()->getLastPrepareQuery(),$timeout);
            $ret = null;
            if($stmt){
                $ret = $stmt->execute($this->queryBuilder()->getLastBindParams(),$timeout);
            }else{
                $ret = false;
            }
            if($this->onQuery){
                call_user_func($this->onQuery,$ret,$this,$start);
            }
            if($ret === false && $this->mysqlClient()->errno){
                throw new Exception($this->mysqlClient()->error);
            }
            return $ret;
        }catch (\Throwable $exception){
            throw $exception;
        }finally{
            $this->reset();
        }
    }

    function rawQuery(string $query,float $timeout = null)
    {
        $builder = new QueryBuilder();
        $builder->raw($query);
        $this->lastQueryBuilder = $builder;
        $start = microtime(true);
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        $this->connect();
        $ret = $this->mysqlClient()->query($query,$timeout);
        if($this->onQuery){
            call_user_func($this->onQuery,$ret,$this,$start);
        }
        if($ret === false && $this->mysqlClient()->errno){
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