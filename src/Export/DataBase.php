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

        $tableNames = array_map(function ($tableName) {
            return current($tableName);
        }, $tableNames);

        // 指定的表
        $inTable = $this->config->getInTable();
        if ($inTable) {
            $tableNames = array_intersect($tableNames, $inTable);
        }

        // 排除的表
        $notInTable = $this->config->getNotInTable();
        if ($notInTable) {
            $tableNames = array_diff($tableNames, $notInTable);
        }

        foreach ($tableNames as $tableName) {
            $tables[] = new Table($this->client, $tableName, $this->config);
        }
        return $tables;
    }


    function export(&$output, bool $onlyStruct = false)
    {
        $startTime = date('Y-m-d H:i:s');

        $tables = $this->showTables();

        $serverInfo = $this->client->mysqlClient()->serverInfo;
        $version = current(current($this->client->rawQuery('SELECT VERSION();')));
        $front = '-- EasySwoole MySQL dump, for ' . PHP_OS . PHP_EOL;
        $front .= '--' . PHP_EOL;
        $front .= "-- Host: {$serverInfo['host']}    Database: {$serverInfo['database']}" . PHP_EOL;
        $front .= '-- ------------------------------------------------------' . PHP_EOL;
        $front .= "-- Server version	{$version}   Date: $startTime" . PHP_EOL . PHP_EOL;

        Utility::writeSql($output, $front);

        foreach ($tables as $table) {
            if (!$table instanceof Table) continue;
            $table->export($output, $onlyStruct);
        }

        $completedTime = date('Y-m-d H:i:s');
        $end = "-- Dump completed on {$completedTime}" . PHP_EOL;
        Utility::writeSql($output, $end);
    }
}