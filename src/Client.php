<?php


namespace EasySwoole\Mysqli;


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

    function execBuilder()
    {

    }

    function rawQuery(string $query)
    {

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
           return (bool)$this->mysqlClient->connect($this->config);
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