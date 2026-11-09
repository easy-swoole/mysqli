<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

/** 可用于数据库连接的 TLS 协议版本。 */
enum TlsVersion: string
{
    /** 使用 TLS 1.2 协议。 */
    case Tls12 = 'TLSv1.2';
    /** 使用 TLS 1.3 协议。 */
    case Tls13 = 'TLSv1.3';
}
