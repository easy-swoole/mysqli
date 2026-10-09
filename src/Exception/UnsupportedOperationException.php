<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Exception;

/**
 * 客户端明确不支持的操作，在 SQL 发送前拒绝执行。
 */
class UnsupportedOperationException extends Exception
{
}
