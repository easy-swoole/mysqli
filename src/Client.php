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
            throw new Exception("prepare sql {$lastPrepareSql} error , {$throwable->getMessage()}");
        }

        if(!$stmt){
            throw new Exception("mysqli prepare {$lastPrepareSql} fail");
        }
        $p = '';
        foreach ($builder->getLastBindParams() as $item){
            $p .= $this->determineType($item);
        }

        try {
            if(!empty($p)){
                $p = [$p];
                foreach ($builder->getLastBindParams() as $param){
                    $p[] = $param;
                }
                $stmt->bind_param(...$p);
            }
        }catch (\Throwable $throwable){
            throw new Exception("Sql {$lastPrepareSql} error {$throwable->getMessage()}");
        }

        $stmt->execute();
        $ret = $stmt->get_result();
        if($ret instanceof mysqli_result){
            $ret = $ret->fetch_all(MYSQLI_ASSOC);
        }
        $this->lastInsertId = $stmt->insert_id;
        $this->lastAffectRows = $stmt->affected_rows;
        $stmt->close();
        if($this->onQuery){
            call_user_func($this->onQuery,$ret,$this,$start);
        }
        if($ret === false && $this->mysqlClient()->errno){
            throw new Exception($this->mysqlClient()->error);
        }
        return $ret;
    }


    function rawQuery(string $query)
    {
        $this->lastInsertId = null;
        $this->lastAffectRows = null;
        $builder = new QueryBuilder();
        $builder->raw($query);
        $start = microtime(true);
        $this->connect();
        try {
            $ret = $this->mysqlClient()->query($query);
        }catch (\Throwable $throwable){
            throw new Exception("exec sql {$query} error , {$throwable->getMessage()}");
        }

        if($ret instanceof mysqli_result){
            $ret = $ret->fetch_all(MYSQLI_ASSOC);
        }
        $this->lastInsertId = $this->mysqlClient()->insert_id;
        $this->lastAffectRows = $this->mysqlClient()->affected_rows;
        if($this->onQuery){
            call_user_func($this->onQuery,$ret,$this,$start);
        }
        if($ret === false && $this->mysqlClient()->errno){
            throw new Exception($this->mysqlClient()->error);
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
        $ret =  $this->mysqlClient->connect(...$c);
        if($ret){
            $this->mysqlClient->select_db($this->config->getDatabase());
            $this->mysqlClient->set_charset($this->config->getCharset());
            $this->mysqliHasConnected = true;
        }
        return $ret;
    }

    function close():bool
    {
        if($this->mysqliHasConnected){
            $this->mysqlClient->close();
            $this->mysqliHasConnected = false;
        }
        $this->mysqlClient = null;

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