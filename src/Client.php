<?php


namespace EasySwoole\Mysqli;


use EasySwoole\Mysqli\Exception\Exception;
use Swoole\Coroutine\MySQL;

class Client
{
    protected $config;

    protected $mysqlClient;

    protected $queryBuilder;

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
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        $this->connect();
        $stmt = $this->mysqlClient()->prepare($this->queryBuilder()->getLastPrepareQuery(),$timeout);
        $ret = null;
        if($stmt){
            $ret = $stmt->execute($this->queryBuilder()->getLastBindParams(),$timeout);
        }
        if($this->mysqlClient()->errno){
            throw new Exception($this->mysqlClient()->error);
        }
        return $ret;
    }

    function rawQuery(string $query,float $timeout = null)
    {
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        $this->connect();
        $ret = $this->mysqlClient()->query($query,$timeout);
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