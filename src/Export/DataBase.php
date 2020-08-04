<?php


namespace EasySwoole\Mysqli\Export;


use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Utility;

class DataBase
{
    protected $client;

    protected $config;

    function __construct(Client $client, ?Config $config = null)
    {
        $this->client = $client;

        if ($config == null) $config = new Config();
        $this->config = $config;
    }

    function showTables(): array
    {
        $tables = [];
        $tableNames = $this->client->rawQuery('SHOW TABLES;');
        foreach ($tableNames as $tableName) {
            $tables[] = new Table($this->client, current($tableName),$this->config);
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

        Utility::writeSql($output, $front);

        foreach ($tables as $table) {
            if (!$table instanceof Table) continue;
            $table->export($output, $onlyStruct);
        }
    }
}