<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    private ?Client $client = null;
    private string $table;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            $this->markTestSkipped('Set FAST_DB_TEST_* or MYSQLI_TEST_* to the EasySwoole FastDb test database.');
        }
        $this->client = new Client(new Config(MYSQL_CONFIG));
        $this->table = 'mysqli_coroutine_metadata_' . bin2hex(random_bytes(6));
        $this->client->rawQuery("CREATE TABLE `{$this->table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            value VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->rawQuery("DROP TABLE IF EXISTS `{$this->table}`");
            } finally {
                $this->client->close();
                $this->client = null;
            }
        }
    }

    public function testQueryBuilderInsertReportsAutoIncrementAndAffectedRows(): void
    {
        self::assertTrue($this->client->query((new QueryBuilder())->insert($this->table, ['value' => 'first'])));
        self::assertSame(1, $this->client->getLastInsertId());
        self::assertSame(1, $this->client->getLastAffectRows());

        self::assertTrue($this->client->query((new QueryBuilder())->insert($this->table, ['value' => 'second'])));
        self::assertSame(2, $this->client->getLastInsertId());
        self::assertSame(1, $this->client->getLastAffectRows());
    }

    public function testDirectPreparedInsertUpdatesClientMetadata(): void
    {
        $statement = $this->client->prepare("INSERT INTO `{$this->table}` (value) VALUES (?)");
        try {
            self::assertTrue($statement->execute(['prepared']));
        } finally {
            $statement->close();
        }

        self::assertSame(1, $this->client->getLastInsertId());
        self::assertSame(1, $this->client->getLastAffectRows());
    }

    public function testRawInsertUpdateNoChangeAndDeleteMetadata(): void
    {
        self::assertTrue($this->client->rawQuery("INSERT INTO `{$this->table}` (value) VALUES ('before')"));
        $id = $this->client->getLastInsertId();
        self::assertSame(1, $id);
        self::assertSame(1, $this->client->getLastAffectRows());

        self::assertTrue($this->client->rawQuery("UPDATE `{$this->table}` SET value = 'after' WHERE id = {$id}"));
        self::assertSame(0, $this->client->getLastInsertId());
        self::assertSame(1, $this->client->getLastAffectRows());

        self::assertTrue($this->client->rawQuery("UPDATE `{$this->table}` SET value = 'after' WHERE id = {$id}"));
        self::assertSame(0, $this->client->getLastAffectRows());

        self::assertTrue($this->client->rawQuery("DELETE FROM `{$this->table}` WHERE id = {$id}"));
        self::assertSame(1, $this->client->getLastAffectRows());

        self::assertTrue($this->client->rawQuery("DELETE FROM `{$this->table}` WHERE id = {$id}"));
        self::assertSame(0, $this->client->getLastAffectRows());
    }

    public function testFailedStatementClearsPreviousMetadata(): void
    {
        self::assertTrue($this->client->rawQuery("INSERT INTO `{$this->table}` (value) VALUES ('before error')"));
        self::assertSame(1, $this->client->getLastInsertId());

        try {
            $this->client->rawQuery("INSERT INTO `{$this->table}` (missing_column) VALUES (1)");
            self::fail('Expected invalid column error');
        } catch (Exception $error) {
            self::assertSame(1054, $error->getCode());
        }
        self::assertNull($this->client->getLastInsertId());
        self::assertNull($this->client->getLastAffectRows());
    }
}
