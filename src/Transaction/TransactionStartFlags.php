<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Transaction;

/**
 * 开始事务的选项；组合选项显式列出，避免同时指定只读和读写。
 */
enum TransactionStartFlags: int
{
    /** 使用默认事务模式，不指定访问方式或一致性快照。 */
    case None = 0;
    /** 开始事务并立即建立一致性快照，需要服务端隔离级别支持。 */
    case ConsistentSnapshot = 1;
    /** 开始允许读取和写入的事务。 */
    case ReadWrite = 2;
    /** 建立一致性快照，并允许读取和写入。 */
    case ConsistentSnapshotReadWrite = 3;
    /** 开始只读事务，禁止修改非临时表。 */
    case ReadOnly = 4;
    /** 建立一致性快照，并使用只读事务模式。 */
    case ConsistentSnapshotReadOnly = 5;

    /**
     * 返回 START TRANSACTION 对应的完整 SQL。
     */
    public function toSql(): string
    {
        return match ($this) {
            self::None => 'START TRANSACTION',
            self::ConsistentSnapshot => 'START TRANSACTION WITH CONSISTENT SNAPSHOT',
            self::ReadWrite => 'START TRANSACTION READ WRITE',
            self::ConsistentSnapshotReadWrite => 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ WRITE',
            self::ReadOnly => 'START TRANSACTION READ ONLY',
            self::ConsistentSnapshotReadOnly => 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY',
        };
    }
}
