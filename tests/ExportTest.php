<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli\Tests;


use PHPUnit\Framework\TestCase;

class ExportTest extends TestCase
{
    public function testDefault()
    {
        $config = new \EasySwoole\Mysqli\Config();
        $config->setHost('127.0.0.1');
        $config->setUser('root');
        $config->setPort(3306);
        $config->setPassword('gaobinzhan');
        $config->setDatabase('blog');

        $client = new \EasySwoole\Mysqli\Client($config);

        $database = new \EasySwoole\Mysqli\Export\DataBase($client);
        $file = fopen('test.sql','w+');
        $database->export($file,false);

        $this->assertTrue(file_exists('test.sql'));
    }
}