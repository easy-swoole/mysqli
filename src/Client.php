<?php


namespace EasySwoole\Mysqli;


use Swoole\Coroutine\MySQL;

class Client
{
    protected $config;

    protected $mysqlClient;

    function __construct(Config $config)
    {
        $this->config = $config;
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

    function disConnect():bool
    {
        if($this->mysqlClient instanceof MySQL && $this->mysqlClient->connected){
            $this->mysqlClient->close();
        }
        return true;
    }
}