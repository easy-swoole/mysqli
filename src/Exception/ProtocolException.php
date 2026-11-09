<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Exception;

/** 协议数据流格式错误时，绝不能将连接放回连接池。 */
final class ProtocolException extends Exception
{
}
