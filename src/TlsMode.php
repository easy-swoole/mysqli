<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

/** TLS 需要显式启用；启用后绝不回退到明文连接。 */
enum TlsMode: string
{
    case Disabled = 'disabled';
    case Required = 'required';
    case VerifyCA = 'verify_ca';
    case VerifyIdentity = 'verify_identity';
}
