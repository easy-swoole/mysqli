<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli\Tests;


use EasySwoole\Mysqli\Export\Config;
use EasySwoole\Mysqli\Export\DataBase;
use EasySwoole\Mysqli\Export\Event;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    protected $client;


    public function __construct($name = null, array $data = [], $dataName = '')
    {
        $config = new \EasySwoole\Mysqli\Config();
        $config->setHost('127.0.0.1');
        $config->setUser('root');
        $config->setPort(3306);
        $config->setPassword('gaobinzhan');
        $config->setDatabase('blog');
        $config->setTimeout(-1);

        $this->client = new \EasySwoole\Mysqli\Client($config);

        parent::__construct($name, $data, $dataName);
    }

    public function testExport()
    {
        $config = new Config();
        $config->setInTable(['test']);
        $config->setSize(2000);
        $database = new DataBase($this->client, $config);

        $fp = fopen('./test.sql', 'w+');
        $database->export($fp);
        $this->assertTrue(file_exists('./test.sql'));
    }

    public function testImport()
    {
        $config = new Config();
        $config->setSize(20000);
        $database = new DataBase($this->client, $config);
        $result = $database->import('./test.sql', 'r+');
        $this->assertEmpty($result->getErrorSqls());
        $this->assertEmpty($result->getErrorMsgs());
    }

    public function testProcessBar()
    {
        $config = new Config();
        $config->setCallback(\EasySwoole\Mysqli\Export\Event::onBeforeExportTableData, function (\EasySwoole\Mysqli\Client $client, $tableName) {
            echo "Dumping data for table `{$tableName}`" . PHP_EOL;
            return current(current($client->rawQuery("SELECT COUNT(1) FROM {$tableName}")));
        });

        $config->setCallback(\EasySwoole\Mysqli\Export\Event::onExportingTableData, function (\EasySwoole\Mysqli\Client $client, $tableName, $page, $size, $totalCount) {
            $currentCount = ($page * $size) > $totalCount ? $totalCount : ($page * $size);
            printf("[%-100s] %d%% \r", str_repeat('=', $currentCount / $totalCount * 100) . '>', $currentCount / $totalCount * 100);
        });

        $config->setCallback(\EasySwoole\Mysqli\Export\Event::onAfterExportTableData, function () {
            echo PHP_EOL;
        });

        $config->setCallback(\EasySwoole\Mysqli\Export\Event::onBeforeImportTableData, function (\EasySwoole\Mysqli\Client $client, $resource) {
            $totalLine = 0;
            while (!feof($resource)) {
                fgets($resource);
                $totalLine++;
            }
            // 倒回文件指针的位置
            if (!rewind($resource)) {
                throw new \EasySwoole\Mysqli\Exception\DumpException('Failed to reset file pointer position');
            }

            return $totalLine;
        });

        $currentLine = 0;
        $config->setCallback(\EasySwoole\Mysqli\Export\Event::onImportingTableData, function (\EasySwoole\Mysqli\Client $client, $totalCount) use (&$currentLine) {
            $currentCount = ++$currentLine;
            printf("[%-100s] %d%% \r", str_repeat('=', $currentCount / $totalCount * 100) . '>', $currentCount / $totalCount * 100);
        });

        $config->setCallback(\EasySwoole\Mysqli\Export\Event::onAfterImportTableData, function (\EasySwoole\Mysqli\Client $client) {
            echo PHP_EOL;
        });


        $config->setInTable(['test']);
        $config->setSize(3000);
        $database = new DataBase($this->client, $config);
        $fp = fopen('./test.sql', 'w+');
        $database->export($fp);
        $this->assertTrue(file_exists('./test.sql'));

        $result = $database->import('./test.sql');
        $this->assertEmpty($result->getErrorSqls());
        $this->assertEmpty($result->getErrorMsgs());
    }


    public function testEvent()
    {
        $config = new Config();
        $config->setCallback(Event::onWriteFrontNotes, function () {
            return 'This is EasySwoole Mysqli Dump';
        });

        $config->setCallback(Event::onWriteTableStruct, function () {
            return 'Table Struct';
        });

        $config->setCallback(Event::onBeforeWriteTableDataNotes, function () {
            return 'Before';
        });

        $config->setCallback(Event::onAfterWriteTableDataNotes, function () {
            return 'After';
        });

        $config->setCallback(Event::onWriteCompletedNotes, function () {
            return 'Success';
        });

        $config->setInTable(['blog_users']);
        $database = new DataBase($this->client, $config);
        $fp = fopen('./test111.sql', 'w+');
        $database->export($fp);
        $this->assertContains('This is EasySwoole Mysqli Dump', file_get_contents('./test111.sql'));
        $this->assertContains('Table Struct', file_get_contents('./test111.sql'));
        $this->assertContains('Before', file_get_contents('./test111.sql'));
        $this->assertContains('After', file_get_contents('./test111.sql'));
        $this->assertContains('Success', file_get_contents('./test111.sql'));
    }

    public function testAnalyze(){
        $database = new DataBase($this->client);
        $result = $database->analyze('test');
        $this->assertEquals('OK',current($result)['Msg_text']);

        $result = $database->analyze('hiai_notice');
        $this->assertNotEquals('OK',current($result)['Msg_text']);
    }

    public function testCheck(){
        $database = new DataBase($this->client);
        $result = $database->check('test');
        $this->assertEquals('OK',current($result)['Msg_text']);

        $result = $database->check('hiai_notice');
        $this->assertEquals('OK',current($result)['Msg_text']);
    }

    public function testAlter(){
        $database = new DataBase($this->client);
        $result = $database->alter('test');
        $this->assertTrue($result);

        $result = $database->alter('hiai_notice');
        $this->assertTrue($result);
    }

    public function testOptimize()
    {
        $database = new DataBase($this->client);
        $result = $database->optimize('test');
        $this->assertFalse($result);

        $result = $database->optimize('hiai_notice');
        $this->assertTrue($result);
    }

    public function testRepair()
    {
        $database = new DataBase($this->client);
        $result = $database->repair('test');
        $this->assertFalse($result);

        $result = $database->repair('hiai_notice');
        $this->assertTrue($result);
    }

    public function testConfig()
    {
        $result = '';
        $config = new Config();
        $tableName = 'blog_users';
        $config->setInTable([$tableName]);
        $config->setLockTablesWrite(true);
        $config->setStartTransaction(true);
        $config->setCreateTableIsNotExist(true);
        $config->setCloseForeignKeyChecks(true);
        $config->setNames('utf8mb4');
        $config->setDrop(true);
        $database = new DataBase($this->client, $config);
        $database->export($result);

        $this->assertContains("LOCK TABLES `{$tableName}` WRITE;", $result);
        $this->assertContains("UNLOCK TABLES;", $result);
        $this->assertContains("BEGIN", $result);
        $this->assertContains("COMMIT", $result);
        $this->assertContains("DROP", $result);
        $this->assertContains("CREATE TABLE IF NOT EXISTS ", $result);
        $this->assertContains("SET NAMES utf8mb4;", $result);
        $this->assertContains("SET FOREIGN_KEY_CHECKS = 0;", $result);
        $this->assertContains("DROP TABLE IF EXISTS `{$tableName}`", $result);


        $result = '';
        $config = new Config();
        $tableName = 'blog_users';
        $config->setInTable([$tableName]);
        $config->setLockTablesWrite(false);
        $config->setStartTransaction(false);
        $config->setCreateTableIsNotExist(false);
        $config->setCloseForeignKeyChecks(false);
        $config->setNames(false);
        $config->setDrop(false);
        $database = new DataBase($this->client, $config);
        $database->export($result);

        $this->assertNotContains("LOCK TABLES `{$tableName}` WRITE;", $result);
        $this->assertNotContains("UNLOCK TABLES;", $result);
        $this->assertNotContains("BEGIN", $result);
        $this->assertNotContains("COMMIT", $result);
        $this->assertNotContains("DROP", $result);
        $this->assertNotContains("CREATE TABLE IF NOT EXISTS ", $result);
        $this->assertNotContains("SET NAMES utf8mb4;", $result);
        $this->assertNotContains("SET FOREIGN_KEY_CHECKS = 0;", $result);
        $this->assertNotContains("DROP TABLE IF EXISTS `{$tableName}`", $result);
    }
}