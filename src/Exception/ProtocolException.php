<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Exception;

/** A malformed protocol stream must never be returned to the connection pool. */
final class ProtocolException extends Exception
{
}
