<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\UnsupportedOperationException;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class StoredProcedureRestrictionTest extends TestCase
{
    public function testRejectedCallDoesNotExecuteProcedureOrDamageTransaction(): void
    {
        if (MYSQL_CONFIG['host'] === '') { $this->markTestSkipped('Set MYSQLI_TEST_* to a test database.'); }
        $client = new Client(new Config(MYSQL_CONFIG));
        $name = 'mysqli_call_guard_' . bin2hex(random_bytes(6));
        try {
            $client->rawQuery("CREATE TEMPORARY TABLE `{$name}` (value INT) ENGINE=InnoDB");
            // 无结果的存储过程同样禁止调用，若被执行则插入记录可检测到。
            $client->rawQuery("CREATE PROCEDURE `{$name}`() INSERT INTO `{$name}` VALUES (1)");
            $client->rawQuery('BEGIN');
            $connection = $client->mysqlClient();
            $sql = "/* call test */ CALL `{$name}`()";
            foreach ([fn() => $client->rawQuery($sql), fn() => $client->prepare($sql),
                fn() => $client->query((new QueryBuilder())->raw($sql)),
                fn() => $connection->query($sql), fn() => $connection->prepare($sql)] as $operation) {
                try {
                    $operation();
                    self::fail('Expected local CALL rejection');
                } catch (UnsupportedOperationException $error) {
                    self::assertSame(0, $error->getCode());
                    self::assertTrue($connection->inTransaction());
                    self::assertTrue($client->ping());
                    self::assertSame([['total' => 0]], $client->rawQuery("SELECT COUNT(*) AS total FROM `{$name}`"));
                }
            }
            $connection->rollback();
            self::assertSame([['value' => 'CALL']], $client->query((new QueryBuilder())->raw("SELECT 'CALL' AS value")));
        } finally {
            try { $client->mysqlClient()?->rollback(); }
            catch (\Throwable) { }
            try { $client->rawQuery("DROP PROCEDURE IF EXISTS `{$name}`"); }
            finally { $client->close(); }
        }
    }
}
