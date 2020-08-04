<?php


namespace EasySwoole\Mysqli\Export;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Utility;

class Table
{
    protected $client;
    protected $tableName;
    protected $config;

    function __construct(Client $client, string $tableName, Config $config)
    {
        $this->client = $client;
        $this->tableName = $tableName;
        $this->config = $config;
    }

    function createTableSql()
    {
        $result = $this->client->rawQuery("SHOW CREATE TABLE `{$this->tableName}`");
        return $result[0]['Create Table'];
    }


    function export(&$output, bool $onlyStruct = false)
    {
        $structure = '--' . PHP_EOL;
        $structure .= "-- Table structure for table `{$this->tableName}`" . PHP_EOL;
        $structure .= '--' . PHP_EOL . PHP_EOL;
        $structure .= "DROP TABLE IF EXISTS `{$this->tableName}`;" . PHP_EOL;
        $structure .= $this->createTableSql() . ';' . PHP_EOL . PHP_EOL;


        Utility::writeSql($output, $structure);

        if ($onlyStruct) return;

        $data = '--' . PHP_EOL;
        $data .= "-- Dumping data for table `{$this->tableName}`" . PHP_EOL;
        $data .= '--' . PHP_EOL . PHP_EOL;
        $data .= "LOCK TABLES `{$this->tableName}` WRITE;" . PHP_EOL;

        Utility::writeSql($output, $data);

        $page = $this->config->getPage();
        $size = $this->config->getSize();
        while (true) {
            $insertSql = $this->getInsertSql($page, $size);
            if (empty($insertSql)) break;

            Utility::writeSql($output, $insertSql);

            $page++;
        }

        $data = 'UNLOCK TABLES;' . PHP_EOL . PHP_EOL;

        Utility::writeSql($output, $data);
    }

    private function getInsertSql($page, $size): string
    {
        $limit = ($page - 1) * $size;
        $this->client->queryBuilder()->limit($limit, $size)->get($this->tableName);
        $result = $this->client->execBuilder();
        $queryBuilder = new QueryBuilder();

        $data = '';
        foreach ($result as $item) {

            $item = array_map(function ($v) {
                return str_replace(["\r", "\n"], ['\r', '\n'], addslashes($v));
            }, $item);

            $queryBuilder->insert($this->tableName, $item);
            $insertSql = $queryBuilder->getLastQuery();
            $queryBuilder->reset();

            $data .= "{$insertSql};" . PHP_EOL;
        }

        return $data;
    }
}