<?php
/**
 * @author gaobinzhan <gaobinzhan@gmail.com>
 */


namespace EasySwoole\Mysqli\Export;


class Result
{
    protected $successNum = 0;

    protected $errorNum = 0;

    protected $errorSqls = [];

    protected $errorMsgs = [];

    /**
     * @return int
     */
    public function getErrorNum(): int
    {
        return $this->errorNum;
    }

    /**
     * @param int $errorNum
     */
    public function setErrorNum(int $errorNum): void
    {
        $this->errorNum = $errorNum;
    }

    /**
     * @return int
     */
    public function getSuccessNum(): int
    {
        return $this->successNum;
    }

    /**
     * @param int $successNum
     */
    public function setSuccessNum(int $successNum): void
    {
        $this->successNum = $successNum;
    }

    /**
     * @return array
     */
    public function getErrorSqls(): array
    {
        return $this->errorSqls;
    }

    /**
     * @param string $errorSql
     */
    public function setErrorSql(string $errorSql): void
    {
        $this->errorSqls[] = $errorSql;
    }

    /**
     * @return array
     */
    public function getErrorMsgs(): array
    {
        return $this->errorMsgs;
    }

    /**
     * @param string $errorMsg
     */
    public function setErrorMsg(string $errorMsg): void
    {
        $this->errorMsgs[] = $errorMsg;
    }
}