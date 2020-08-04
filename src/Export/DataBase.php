<?php


namespace EasySwoole\Mysqli\Export;


use EasySwoole\Mysqli\Client;

class DataBase
{
    protected $client;

    function __construct(Client $client)
    {
        $this->client = $client;
    }

    function showTables(): array
    {
        $tables = [];
        $tableNames = $this->client->rawQuery('SHOW TABLES;');
        foreach ($tableNames as $tableName) {
            $tables[] = new Table($this->client, current($tableName));
        }
        return $tables;
    }


    function export(&$output, bool $onlyStruct = false)
    {
        $tables = $this->showTables();

        $serverInfo = $this->client->mysqlClient()->serverInfo;
        $version = current(current($this->client->rawQuery('SELECT VERSION();')));
        $front = '-- EasySwoole MySQL dump, for ' . PHP_OS . PHP_EOL;
        $front .= '--' . PHP_EOL;
        $front .= "-- Host: {$serverInfo['host']}    Database: {$serverInfo['database']}" . PHP_EOL;
        $front .= '-- ------------------------------------------------------' . PHP_EOL;
        $front .= "-- Server version	{$version}" . PHP_EOL . PHP_EOL;

        if (is_resource($output)) {
            fwrite($output, $front);
        } else {
            $output = $front;
        }

        foreach ($tables as $table) {
            if (!$table instanceof Table) continue;
            $table->export($output, $onlyStruct);
        }
    }
}