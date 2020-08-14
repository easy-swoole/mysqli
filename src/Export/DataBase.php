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


    function export(&$output)
    {
        $startTime = date('Y-m-d H:i:s');

        $tables = $this->showTables();
        if (!$tables) return;

        /** EasySwoole Mysql dump start */
        $serverInfo = $this->client->mysqlClient()->serverInfo;
        $version = current(current($this->client->rawQuery('SELECT VERSION();')));
        $front = '-- EasySwoole Mysql dump, for ' . PHP_OS . PHP_EOL;
        $front .= '--' . PHP_EOL;
        $front .= "-- Host: {$serverInfo['host']}    Database: {$serverInfo['database']}" . PHP_EOL;
        $front .= '-- ------------------------------------------------------' . PHP_EOL;
        $front .= "-- Server version	{$version}   Date: $startTime" . PHP_EOL . PHP_EOL;

        /** names */
        $names = $this->config->getNames();
        if ($names) {
            $front .= "SET NAMES {$names};" . PHP_EOL;
        }

        /** 外键约束 */
        if ($this->config->isCloseForeignKeyChecks()) {
            $front .= 'SET FOREIGN_KEY_CHECKS = 0;' . PHP_EOL;
        };

        $front .= PHP_EOL;

        Utility::writeSql($output, $front);

        /** Table data */
        foreach ($tables as $table) {
            $table->export($output);
        }

        /** EasySwoole Mysql dump completed */
        $completedTime = date('Y-m-d H:i:s');
        $end = "-- Dump completed on {$completedTime}" . PHP_EOL;
        Utility::writeSql($output, $end);
    }
}