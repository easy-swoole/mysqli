<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Transaction;

/**
 * 提交或回滚的选项；显式列出合法组合，避免相互矛盾的结束方式。
 */
enum TransactionCompletionFlags: int
{
    /** 按服务端 completion_type 的默认行为结束事务。 */
    case None = 0;
    /** 结束当前事务后立即开始新事务。 */
    case Chain = 1;
    /** 结束当前事务，不开始新事务；连接是否释放由服务端默认行为决定。 */
    case NoChain = 2;
    /** 结束当前事务并释放连接。 */
    case Release = 4;
    /** 结束当前事务并保留连接；是否开始新事务由服务端默认行为决定。 */
    case NoRelease = 8;
    /** 结束当前事务，不开始新事务，并释放连接。 */
    case NoChainRelease = 6;
    /** 结束当前事务后立即开始新事务，并保留连接。 */
    case ChainNoRelease = 9;
    /** 结束当前事务，不开始新事务，并保留连接。 */
    case NoChainNoRelease = 10;

    /**
     * 返回 COMMIT 或 ROLLBACK 后的 SQL 选项。
     */
    public function toSqlSuffix(): string
    {
        return match ($this) {
            self::None => '',
            self::Chain => ' AND CHAIN',
            self::NoChain => ' AND NO CHAIN',
            self::Release => ' RELEASE',
            self::NoRelease => ' NO RELEASE',
            self::NoChainRelease => ' AND NO CHAIN RELEASE',
            self::ChainNoRelease => ' AND CHAIN NO RELEASE',
            self::NoChainNoRelease => ' AND NO CHAIN NO RELEASE',
        };
    }

    /**
     * 判断成功结束事务后是否需要关闭本地连接。
     */
    public function releasesConnection(): bool
    {
        return $this === self::Release || $this === self::NoChainRelease;
    }
}
