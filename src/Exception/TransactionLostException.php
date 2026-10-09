<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Exception;

/**
 * 事务因连接丢失而失效，禁止透明重连后继续执行。
 */
class TransactionLostException extends Exception
{
}
