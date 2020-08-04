<?php


namespace EasySwoole\Mysqli\Export;

use EasySwoole\DDL\Blueprint\Table as DDLTable;
use EasySwoole\Mysqli\Client;

class Table
{
    protected $client;
    protected $tableName;
    function __construct(Client $client,string $tableName)
    {
        $this->client = $client;
        $this->tableName = $tableName;
    }

    function ddl():DDLTable
    {

    }

    function export(&$output,bool $onlyStruct = false)
    {
        $result = '';
        if(is_resource($output)){
            fwrite($output,$result);
        }else{
            $output = $result;
        }
    }
}