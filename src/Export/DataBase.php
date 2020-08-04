<?php


namespace EasySwoole\Mysqli\Export;


use EasySwoole\Mysqli\Client;

class DataBase
{
    protected $client;

    function __construct(Client  $client)
    {
        $this->client = $client;
    }

    function showTables():array
    {

    }


    function export(&$output,bool $onlyStruct = false)
    {

    }
}