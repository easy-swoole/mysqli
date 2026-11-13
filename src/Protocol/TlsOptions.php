<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\TlsMode;
use EasySwoole\Mysqli\TlsVersion;

/** @internal 生成受支持的 TLS 选项，在发送任何凭据前完成配置校验。 */
final class TlsOptions
{
    public static function fromConfig(Config $config): array
    {
        return self::forHandshake($config, $config->getTlsMode(), self::expectedName($config));
    }

    /** @internal 为一次握手固定模式、SNI 选项和证书身份。 */
    public static function handshakeConfiguration(Config $config, bool $respectDisabledMode = true): array
    {
        $mode = $config->getTlsMode();
        if ($respectDisabledMode && $mode === TlsMode::Disabled) {
            return [null, null];
        }
        $name = self::expectedName($config);
        return [self::forHandshake($config, $mode, $name), $mode === TlsMode::VerifyIdentity ? $name : null];
    }

    /** @internal 返回 SNI 和证书校验共用的预期身份。 */
    private static function expectedName(Config $config): string
    {
        $name = $config->getTlsServerName();
        return $name === '' ? $config->getHost() : $name;
    }

    /** @internal 使用本次连接确定的模式和身份生成选项。 */
    private static function forHandshake(Config $config, TlsMode $mode, string $name): array
    {
        if (str_contains($name, "\0") || preg_match('/\s/', $name)) {
            throw new \InvalidArgumentException('TLS server name must not contain NUL bytes or whitespace');
        }
        // 按 RFC 6066，SNI 只携带 DNS 名，不发送 IP 字面量；同时去除 DNS 名末尾的根域点。
        $serverName = filter_var($name, FILTER_VALIDATE_IP) !== false ? '' : rtrim($name, '.');
        $options = [
            'open_ssl' => true,
            'ssl_verify_peer' => in_array($mode, [TlsMode::VerifyCA, TlsMode::VerifyIdentity], true),
            'ssl_allow_self_signed' => false,
            // SWOOLE_SNI_COMPAT：身份校验与 SNI 分开；5.1.8、6.2.4 仍需 TlsHandshake 补充分离逻辑。
            // 此处选项及独立 CertificateIdentity 校验不是停止支持 5.1 后就能直接删除的代码。
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
            // 检查当前 Swoole 的 TLS 编译能力，与是否继续支持 5.1 无关。
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
