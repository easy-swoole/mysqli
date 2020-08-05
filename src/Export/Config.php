<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli\Export;


class Config
{
    protected $size = 1000;

    protected $inTable = [];

    protected $notInTable = [];

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
}