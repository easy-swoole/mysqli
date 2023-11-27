<?php


namespace EasySwoole\Mysqli;


use EasySwoole\Mysqli\Exception\Exception;
use mysqli;
use Swoole\Coroutine\MySQL;
use Throwable;

class Client
{
    protected $config;

    protected bool $mysqliHasConnected = false;

    protected MySQL|mysqli|null $mysqlClient = null;

    protected $onQuery;

    function __construct(Config $config)
    {
        $this->config = $config;
    }

    function onQuery(callable $call):Client
    {
        $this->onQuery = $call;
        return $this;
    }

    function query(QueryBuilder $builder,float $timeout = null)
    {
        $start = microtime(true);
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        try{
            $this->connect();
            if($this->config->isUseMysqli()){
                $stmt = $this->mysqlClient->prepare($builder->getLastPrepareQuery());
                if(!$stmt){
                   throw new Exception("prepare {$builder->getLastPrepareQuery()} fail");
                }
                $p = '';
                foreach ($builder->getLastBindParams() as $item){
                    $p .= $this->determineType($item);
                }
                $p = [$p];
                foreach ($builder->getLastBindParams() as $param){
                    $p[] = $param;
                }
                $stmt->bind_param(...$p);
                $stmt->execute();
                $ret = $stmt->get_result();
                $ret = $ret->fetch_all(MYSQLI_ASSOC);
            }else{
                $stmt = $this->mysqlClient()->prepare($builder->getLastPrepareQuery(),$timeout);
                if($stmt){
                    $ret = $stmt->execute($builder->getLastBindParams(),$timeout);
                }else{
                    $ret = false;
                }
            }

            if($this->onQuery){
                call_user_func($this->onQuery,$ret,$this,$start);
            }
            if($ret === false && $this->mysqlClient()->errno){
                throw new Exception($this->mysqlClient()->error);
            }
            return $ret;
        }catch (Throwable $exception){
            throw new Exception($exception->getMessage());
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
        try{
            $this->connect();
            if($this->config->isUseMysqli()){
                $ret = $this->mysqlClient->query($query);
                if($ret){
                    $ret = $ret->fetch_all(MYSQLI_ASSOC);
                }
            }else{
                $ret = $this->mysqlClient()->query($query,$timeout);
            }
            if($this->onQuery){
                call_user_func($this->onQuery,$ret,$this,$start);
            }
            if($ret === false && $this->mysqlClient()->errno){
                throw new Exception($this->mysqlClient()->error);
            }
            return $ret;
        }catch (Throwable $exception){
            throw new Exception($exception->getMessage());
        }

    }

    function mysqlClient():MySQL|mysqli|null
    {
        return $this->mysqlClient;
    }

    function connect():bool
    {
        if($this->config->isUseMysqli()){
            if(!$this->mysqlClient instanceof mysqli){
                $this->mysqlClient = new mysqli();
            }
            $c = [
                'hostname'=>$this->config->getHost(),
                'username'=>$this->config->getUser(),
                'password'=>$this->config->getPassword(),
                'port'=>$this->config->getPort()
            ];
            $ret =  $this->mysqlClient->connect(...$c);
            if($ret){
                $this->mysqlClient->select_db($this->config->getDatabase());
                $this->mysqlClient->set_charset($this->config->getCharset());
                $this->mysqliHasConnected = true;
            }
            return $ret;
        }else{
            if(!$this->mysqlClient instanceof MySQL){
                $this->mysqlClient = new MySQL();
            }
            if(!$this->mysqlClient->connected){
                return (bool)$this->mysqlClient->connect($this->config->toArray());
            }
            return true;
        }

    }

    function close():bool
    {
        if($this->mysqlClient instanceof MySQL){
            if($this->mysqlClient->connected){
                $this->mysqlClient->close();
            }
            $this->mysqlClient = null;
        }else{
            $this->mysqlClient->close();
            $this->mysqliHasConnected = false;
            $this->mysqlClient = null;
        }
        return true;
    }

    function __destruct()
    {
        $this->close();
    }


    protected function determineType($item)
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
}