<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

/** TLS is opt-in; enabled modes never fall back to plaintext. */
enum TlsMode: string
{
    case Disabled = 'disabled';
    case Required = 'required';
    case VerifyCA = 'verify_ca';
    case VerifyIdentity = 'verify_identity';
}
