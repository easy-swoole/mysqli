<?php


namespace EasySwoole\Mysqli\Export;


use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Exception\DumpException;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Utility;

class DataBase
{
    /** @var Client $client */
    protected $client;

    /** @var Config $config */
    protected $config;

    function __construct(Client $client, ?Config $config = null)
    {
        if ($config == null) {
            $config = new Config();
        }

        $this->client = $client;
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


    /**
     * 导出表数据
     * @param $output
     * @throws Exception
     */
    function export(&$output)
    {
        $startTime = date('Y-m-d H:i:s', time());

        $tables = $this->showTables();

        if (!$tables) {
            return;
        }

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
        }

        $front .= PHP_EOL;

        $writeFrontCallback = $this->config->getCallback(Event::onWriteFrontNotes);
        is_callable($writeFrontCallback) && $front = call_user_func($writeFrontCallback, $this->client, $front);
        Utility::writeSql($output, $front);

        /** Table data */
        /** @var Table $table */
        foreach ($tables as $table) {
            $table->export($output);
        }

        /** EasySwoole Mysql dump completed */
        $completedTime = date('Y-m-d H:i:s');
        $end = "-- Dump completed on {$completedTime}" . PHP_EOL;

        $writeCompletedCallback = $this->config->getCallback(Event::onWriteCompletedNotes);
        is_callable($writeCompletedCallback) && $end = call_user_func($writeCompletedCallback, $this->client, $front);
        Utility::writeSql($output, $end);
    }

    /**
     * 导入表数据
     * @param string $filePath
     * @param string $mode
     * @return Result
     * @throws DumpException
     * @throws Exception
     */
    function import(string $filePath, $mode = 'r+'): Result
    {
        // file 文件检测
        $resource = false;
        file_exists($filePath) && $resource = fopen($filePath, $mode);
        if ($resource === false || !is_resource($resource)) {
            throw new DumpException('Not a valid resource');
        }

        // result
        $result = new Result();
        $successNum = 0;
        $errorNum = 0;

        // init sql
        $sqls = [];
        $createTableSql = '';

        // config
        $size = $this->config->getSize();
        $maxFails = $this->config->getMaxFails();
        $continueOnError = $this->config->isContinueOnError();

        $beforeResult = null;
        $beforeCallback = $this->config->getCallback(Event::onBeforeImportTableData);
        is_callable($beforeCallback) && $beforeResult = call_user_func($beforeCallback, $this->client, $resource);

        $importingCallback = $this->config->getCallback(Event::onImportingTableData);

        while (!feof($resource)) {
            $line = fgets($resource);

            is_callable($importingCallback) && call_user_func($importingCallback, $this->client, $beforeResult);

            // 为空 或者 是注释
            if ((trim($line) == '') || preg_match('/^--*?/', $line, $match)) {
                if (empty($sqls)) continue;
            } else if (!preg_match('/;/', $line, $match) || preg_match('/ENGINE=/', $line, $match)) {
                // 将本次sql语句与创建表sql连接存起来
                $createTableSql .= $line;
                // 如果包含了创建表的最后一句
                if (preg_match('/ENGINE=/', $createTableSql, $match)) {
                    // 则将其合并到sql数组
                    $sqls [] = $createTableSql;
                    // 清空当前，准备下一个表的创建
                    $createTableSql = '';
                }
                if (empty($sqls)) continue;
            } else {
                $sqls[] = $line;
            }

            // 数组长度等于限制长度或者资源到底 执行sql
            if ((count($sqls) == $size) || feof($resource)) {
                foreach ($sqls as $sql) {
                    //重置次数
                    $attempts = 0;
                    $sql = str_replace("\n", "", $sql);
                    while ($attempts <= $maxFails) {
                        try {
                            $this->client->rawQuery(trim($sql));
                            $successNum++;
                            break;
                        } catch (Exception $exception) {
                            $errorNum++;
                            $result->setErrorMsg($exception->getMessage());
                            $result->setErrorSql($sql);

                            if (++$attempts > $maxFails && !$continueOnError) {
                                throw $exception;
                            }
                        }
                    }
                }
                // 清空sql组
                $sqls = [];
            }
        }

        $afterCallback = $this->config->getCallback(Event::onAfterImportTableData);
        is_callable($afterCallback) && call_user_func($afterCallback, $this->client);

        $result->setSuccessNum($successNum);
        $result->setErrorNum($errorNum);
        return $result;
    }


    /**
     * 分析表
     * @param string $tableName
     * @param bool $noWriteToBinLog 语句是否写入二进制日志 默认false 写入
     * @return array|bool
     * @throws Exception
     */
    function analyze(string $tableName, bool $noWriteToBinLog = false)
    {
        $analyzeSql = 'ANALYZE';
        if ($noWriteToBinLog) {
            $analyzeSql .= ' NO_WRITE_TO_BINLOG';
        }

        $analyzeSql .= " TABLE `{$tableName}`;";
        return $this->client->rawQuery($analyzeSql);
    }

    /**
     * 检查表
     * @param string $tableName
     * @param bool $quick 不扫描行，不检查错误的链接。
     * @param bool $fast 只检查没有被正确关闭的表。
     * @param bool $changed 只检查上次检查后被更改的表，和没有被正确关闭的表。
     * @param bool $medium 扫描行，以验证被删除的链接是有效的。也可以计算各行的关键字校验和，并使用计算出的校验和验证这一点。
     * @param bool $extended 对每行的所有关键字进行一个全面的关键字查找。这可以确保表是100％一致的，但是花的时间较长。
     * @return array|bool
     * @throws Exception
     */
    function check(string $tableName, bool $quick = false, bool $fast = false, bool $changed = false, bool $medium = false, bool $extended = false)
    {
        $checkSql = "CHECK TABLE `{$tableName}`";

        if ($quick) {
            $checkSql .= ' QUICK';
        }

        if ($fast) {
            $checkSql .= ' FAST';
        }

        if ($changed) {
            $checkSql .= ' CHANGED';
        }

        if ($medium) {
            $checkSql .= ' MEDIUM';
        }

        if ($extended) {
            $checkSql .= ' EXTENDED';
        }

        return $this->client->rawQuery($checkSql);
    }

    /**
     * 修复表
     * @param string $tableName
     * @param bool $noWriteToBinLog 语句是否写入二进制日志 默认false 写入
     * @param bool $quick
     * @param bool $extended
     * @param bool $useFrm
     * @return bool
     * @throws Exception
     */
    function repair(string $tableName, bool $noWriteToBinLog = false, bool $quick = false, bool $extended = false, bool $useFrm = false)
    {
        $repairSql = 'REPAIR';
        if ($noWriteToBinLog) {
            $repairSql .= ' NO_WRITE_TO_BINLOG';
        }

        $repairSql .= " TABLE `{$tableName}`";

        if ($quick) {
            $repairSql .= ' QUICK';
        }

        if ($extended) {
            $repairSql .= ' EXTENDED';
        }

        if ($useFrm) {
            $repairSql .= ' USE_FRM';
        }

        $result = $this->client->rawQuery($repairSql);

        if (!$result || !is_array($result)) {
            return false;
        }

        foreach ($result as $item) {
            if ($item['Msg_text'] != 'OK') {
                return false;
            }
        }

        return true;
    }

    /**
     * 优化表
     * @param string $tableName
     * @param bool $noWriteToBinLog 语句是否写入二进制日志 默认false 写入
     * @return bool
     * @throws Exception
     */
    function optimize(string $tableName, bool $noWriteToBinLog = false)
    {
        // ALTER TABLE `test` ENGINE = InnoDB;
        $optimizeSql = 'OPTIMIZE';
        if ($noWriteToBinLog) {
            $optimizeSql .= ' NO_WRITE_TO_BINLOG';
        }

        $optimizeSql .= " TABLE `{$tableName}`;";
        $result = $this->client->rawQuery($optimizeSql);

        if (!$result || !is_array($result)) {
            return false;
        }

        foreach ($result as $item) {
            if ($item['Msg_text'] != 'OK') {
                return false;
            }
        }

        return true;
    }


    /**
     * 整理表碎片 innodb
     * @param string $tableName
     * @return bool
     * @throws Exception
     */
    function alter(string $tableName)
    {
        $alterSql = "ALTER TABLE `{$tableName}` ENGINE = InnoDB;";
        return $this->client->rawQuery($alterSql);
    }
}