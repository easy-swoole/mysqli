<?php


namespace EasySwoole\Mysqli;


use EasySwoole\Mysqli\Exception\Exception;
use mysqli;
use mysqli_result;
use Swoole\Coroutine\MySQL;
use Throwable;

class Client
{
    protected $config;

    protected bool $mysqliHasConnected = false;

    protected MySQL|mysqli|null $mysqlClient = null;

    protected $onQuery;

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

    function query(QueryBuilder $builder,float $timeout = null)
    {
        $this->lastInsertId = null;
        $this->lastAffectRows = null;
        $start = microtime(true);
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        try{
            $this->connect();
            if($this->config->isUseMysqli()){
                $stmt = $this->mysqlClient()->prepare($builder->getLastPrepareQuery());
                if(!$stmt){
                    throw new Exception("prepare {$builder->getLastPrepareQuery()} fail");
                }
                $p = '';
                foreach ($builder->getLastBindParams() as $item){
                    $p .= $this->determineType($item);
                }
                if(!empty($p)){
                    $p = [$p];
                    foreach ($builder->getLastBindParams() as $param){
                        $p[] = $param;
                    }
                    $stmt->bind_param(...$p);
                }
                $stmt->execute();
                $ret = $stmt->get_result();
                if($ret instanceof mysqli_result){
                    $ret = $ret->fetch_all(MYSQLI_ASSOC);
                }
                $this->lastInsertId = $stmt->insert_id;
                $this->lastAffectRows = $stmt->affected_rows;
                $stmt->close();
            }else{
                $stmt = $this->mysqlClient()->prepare($builder->getLastPrepareQuery(),$timeout);
                if($stmt){
                    $ret = $stmt->execute($builder->getLastBindParams(),$timeout);
                    $this->lastInsertId = $this->mysqlClient()->insert_id;
                    $this->lastAffectRows = $this->mysqlClient()->affected_rows;
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
        $this->lastInsertId = null;
        $this->lastAffectRows = null;
        $builder = new QueryBuilder();
        $builder->raw($query);
        $start = microtime(true);
        if($timeout === null){
            $timeout = $this->config->getTimeout();
        }
        try{
            $this->connect();
            if($this->config->isUseMysqli()){
                $ret = $this->mysqlClient()->query($query);
                if($ret instanceof mysqli_result){
                    $ret = $ret->fetch_all(MYSQLI_ASSOC);
                }
                $this->lastInsertId = $this->mysqlClient()->insert_id;
                $this->lastAffectRows = $this->mysqlClient()->affected_rows;
            }else{
                $ret = $this->mysqlClient()->query($query,$timeout);
                $this->lastInsertId = $this->mysqlClient()->insert_id;
                $this->lastAffectRows = $this->mysqlClient()->affected_rows;
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
        }else if($this->mysqlClient instanceof mysqli){
            if($this->mysqliHasConnected){
                $this->mysqlClient->close();
                $this->mysqliHasConnected = false;
            }
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

    public function getLastInsertId(): int|string|null
    {
        return $this->lastInsertId;
    }

    public function getLastAffectRows(): int|string|null
    {
        return $this->lastAffectRows;
    }
}