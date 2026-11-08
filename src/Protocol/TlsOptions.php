<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\TlsMode;

/** @internal Build only supported TLS options; validate before any credentials are sent. */
final class TlsOptions
{
    public static function fromConfig(Config $config): array
    {
        $mode = $config->getTlsMode();
        $options = [
            'open_ssl' => true,
            'ssl_verify_peer' => in_array($mode, [TlsMode::VerifyCA, TlsMode::VerifyIdentity], true),
            'ssl_allow_self_signed' => false,
            // Verify identity below using the peer certificate, including IP SANs on Swoole 5.1.
            'ssl_verify_host' => false,
            'ssl_host_name' => '',
        ];
        $protocols = 0;
        foreach ($config->getTlsVersions() as $version) {
            $constant = match ($version) {
                'TLSv1.2' => 'SWOOLE_SSL_TLSv1_2',
                'TLSv1.3' => 'SWOOLE_SSL_TLSv1_3',
                default => throw new \InvalidArgumentException('Only TLSv1.2 and TLSv1.3 are supported'),
            };
            if (!defined($constant)) {
                throw new \RuntimeException("Swoole does not support {$version}");
            }
            $protocols |= constant($constant);
        }
        if ($protocols === 0) {
            throw new \InvalidArgumentException('tlsVersions must not be empty');
        }
        $options['ssl_protocols'] = $protocols;
        foreach (['tlsCaFile' => 'ssl_cafile', 'tlsCaPath' => 'ssl_capath',
                  'tlsCertFile' => 'ssl_cert_file', 'tlsKeyFile' => 'ssl_key_file'] as $property => $option) {
            $path = $config->{'get' . ucfirst($property)}();
            if ($path === '') { continue; }
            if (!is_readable($path) || ($property === 'tlsCaPath' ? !is_dir($path) : !is_file($path))) {
                throw new \InvalidArgumentException("{$property} must be a readable " . ($property === 'tlsCaPath' ? 'directory' : 'file'));
            }
            $options[$option] = $path;
        }
        if (($config->getTlsCertFile() === '') !== ($config->getTlsKeyFile() === '')) {
            throw new \InvalidArgumentException('tlsCertFile and tlsKeyFile must be configured together');
        }
        if ($config->getTlsKeyPassphrase() !== '') {
            $options['ssl_passphrase'] = $config->getTlsKeyPassphrase();
        }
        return $options;
    }
}
