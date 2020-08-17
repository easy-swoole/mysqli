<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli\Export;


class Config
{
    /** 每次读取数据的行数 */
    protected $size = 1000;

    /** 指定导出表 */
    protected $inTable = [];

    /** 排除导出表 */
    protected $notInTable = [];

    /** 是否生成dropTableSql */
    protected $drop = true;

    /** 创建表检测是否存在 */
    protected $createTableIsNotExist = true;

    /** set names */
    protected $names = 'utf8mb4';

    /** 关闭外键约束 */
    protected $closeForeignKeyChecks = true;

    /** 开启写锁 */
    protected $lockTablesWrite = true;

    /** 开启事务 */
    protected $startTransaction = true;

    /** 仅导出表结构 */
    protected $onlyStruct = false;

    /** debug 进度条 */
    protected $debug = false;

    /**
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * @param int $size
     */
    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    /**
     * @return array
     */
    public function getInTable(): array
    {
        return $this->inTable;
    }

    /**
     * @param array $inTable
     */
    public function setInTable(array $inTable): void
    {
        $this->inTable = $inTable;
    }

    /**
     * @return array
     */
    public function getNotInTable(): array
    {
        return $this->notInTable;
    }

    /**
     * @param array $notInTable
     */
    public function setNotInTable(array $notInTable): void
    {
        $this->notInTable = $notInTable;
    }

    /**
     * @return bool
     */
    public function isDrop(): bool
    {
        return $this->drop;
    }

    /**
     * @param bool $drop
     */
    public function setDrop(bool $drop): void
    {
        $this->drop = $drop;
    }

    /**
     * @return bool
     */
    public function isCreateTableIsNotExist(): bool
    {
        return $this->createTableIsNotExist;
    }

    /**
     * @param bool $createTableIsNotExist
     */
    public function setCreateTableIsNotExist(bool $createTableIsNotExist): void
    {
        $this->createTableIsNotExist = $createTableIsNotExist;
    }

    /**
     * @return string
     */
    public function getNames(): string
    {
        return $this->names;
    }

    /**
     * @param string $names
     */
    public function setNames(string $names): void
    {
        $this->names = $names;
    }

    /**
     * @return bool
     */
    public function isCloseForeignKeyChecks(): bool
    {
        return $this->closeForeignKeyChecks;
    }

    /**
     * @param bool $closeForeignKeyChecks
     */
    public function setCloseForeignKeyChecks(bool $closeForeignKeyChecks): void
    {
        $this->closeForeignKeyChecks = $closeForeignKeyChecks;
    }

    /**
     * @return bool
     */
    public function isLockTablesWrite(): bool
    {
        return $this->lockTablesWrite;
    }

    /**
     * @param bool $lockTablesWrite
     */
    public function setLockTablesWrite(bool $lockTablesWrite): void
    {
        $this->lockTablesWrite = $lockTablesWrite;
    }

    /**
     * @return bool
     */
    public function isStartTransaction(): bool
    {
        return $this->startTransaction;
    }

    /**
     * @param bool $startTransaction
     */
    public function setStartTransaction(bool $startTransaction): void
    {
        $this->startTransaction = $startTransaction;
    }

    /**
     * @return bool
     */
    public function isOnlyStruct(): bool
    {
        return $this->onlyStruct;
    }

    /**
     * @param bool $onlyStruct
     */
    public function setOnlyStruct(bool $onlyStruct): void
    {
        $this->onlyStruct = $onlyStruct;
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @param bool $debug
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }
}