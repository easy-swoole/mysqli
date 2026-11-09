<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\TlsMode;
use EasySwoole\Mysqli\TlsVersion;

/** @internal Build only supported TLS options; validate before any credentials are sent. */
final class TlsOptions
{
    public static function fromConfig(Config $config): array
    {
        $mode = $config->getTlsMode();
        $name = $config->getTlsServerName() !== '' ? $config->getTlsServerName() : $config->getHost();
        if (str_contains($name, "\0") || preg_match('/\s/', $name)) {
            throw new \InvalidArgumentException('TLS server name must not contain NUL bytes or whitespace');
        }
        // RFC 6066 SNI carries DNS names, not IP literals. Normalize a DNS root dot.
        $serverName = filter_var($name, FILTER_VALIDATE_IP) !== false ? '' : rtrim($name, '.');
        $options = [
            'open_ssl' => true,
            'ssl_verify_peer' => in_array($mode, [TlsMode::VerifyCA, TlsMode::VerifyIdentity], true),
            'ssl_allow_self_signed' => false,
            // Identity validation is separate from SNI, including on Swoole 5.1.
            'ssl_verify_host' => false,
            'ssl_host_name' => $serverName,
        ];
        $protocols = 0;
        foreach ($config->getTlsVersions() as $version) {
            $tlsVersion = TlsVersion::tryFrom($version)
                ?? throw new \InvalidArgumentException('Only TLSv1.2 and TLSv1.3 are supported');
            $constant = match ($tlsVersion) {
                TlsVersion::Tls12 => 'SWOOLE_SSL_TLSv1_2',
                TlsVersion::Tls13 => 'SWOOLE_SSL_TLSv1_3',
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
