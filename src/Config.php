<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

use EasySwoole\Spl\SplBean;

class Config extends SplBean
{
    protected string $host = '127.0.0.1';
    protected string $user = '';
    protected string $password = '';
    protected string $database = '';
    protected int $port = 3306;
    protected float $timeout = 3.0;
    protected string $charset = 'utf8mb4';
    protected float $maxConnectTime = 1.0;
    protected bool $compress = false;
    protected TlsMode|string $tlsMode = 'disabled';
    protected string $tlsCaFile = '';
    protected string $tlsCaPath = '';
    protected string $tlsCertFile = '';
    protected string $tlsKeyFile = '';
    protected string $tlsKeyPassphrase = '';
    protected string $tlsServerName = '';
    protected array $tlsVersions = ['TLSv1.2', 'TLSv1.3'];

    /**
     * 获取数据库主机地址。
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * 设置数据库主机地址。
     */
    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    /**
     * 获取数据库用户名。
     */
    public function getUser(): string
    {
        return $this->user;
    }

    /**
     * 设置数据库用户名。
     */
    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    /**
     * 获取数据库密码。
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * 设置数据库密码。
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /**
     * 获取数据库名称。
     */
    public function getDatabase(): string
    {
        return $this->database;
    }

    /**
     * 设置数据库名称。
     */
    public function setDatabase(string $database): void
    {
        $this->database = $database;
    }

    /**
     * 获取数据库端口。
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * 设置数据库端口。
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    /**
     * 获取默认查询超时，单位为秒。
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * 设置默认查询超时，单位为秒。
     */
    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * 获取连接字符集。
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * 设置连接字符集。
     */
    public function setCharset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * 获取完整连接过程的超时上限，单位为秒。
     */
    public function getMaxConnectTime(): float
    {
        return $this->maxConnectTime;
    }

    /**
     * 设置完整连接过程的超时上限，单位为秒。
     */
    public function setMaxConnectTime(float $maxConnectTime): void
    {
        $this->maxConnectTime = $maxConnectTime;
    }

    /**
     * 获取是否请求启用协议压缩。
     */
    public function isCompress(): bool
    {
        return $this->compress;
    }

    /**
     * 设置是否请求启用协议压缩。
     */
    public function setCompress(bool $compress): void
    {
        $this->compress = $compress;
    }

    /** 默认禁用 TLS。无效模式名称会被拒绝，不会降级连接。 */
    public function getTlsMode(): TlsMode
    {
        return $this->tlsMode instanceof TlsMode ? $this->tlsMode : TlsMode::from($this->tlsMode);
    }

    public function setTlsMode(TlsMode|string $mode): void
    {
        $this->tlsMode = $mode instanceof TlsMode ? $mode : TlsMode::from($mode);
    }

    public function getTlsCaFile(): string { return $this->tlsCaFile; }
    public function setTlsCaFile(string $value): void { $this->tlsCaFile = $value; }

    public function getTlsCaPath(): string { return $this->tlsCaPath; }
    public function setTlsCaPath(string $value): void { $this->tlsCaPath = $value; }

    public function getTlsCertFile(): string { return $this->tlsCertFile; }
    public function setTlsCertFile(string $value): void { $this->tlsCertFile = $value; }

    public function getTlsKeyFile(): string { return $this->tlsKeyFile; }
    public function setTlsKeyFile(string $value): void { $this->tlsKeyFile = $value; }

    public function getTlsKeyPassphrase(): string { return $this->tlsKeyPassphrase; }
    public function setTlsKeyPassphrase(string $value): void { $this->tlsKeyPassphrase = $value; }

    public function getTlsServerName(): string { return $this->tlsServerName; }
    public function setTlsServerName(string $value): void { $this->tlsServerName = $value; }

    /** @return array<array-key, string> 返回字符串版本名，兼容原有配置读取方式。 */
    public function getTlsVersions(): array
    {
        return array_map(static function (mixed $version): string {
            if ($version instanceof TlsVersion) {
                return $version->value;
            }
            if (!is_string($version)) {
                throw new \InvalidArgumentException('Only TLSv1.2 and TLSv1.3 are supported');
            }
            return $version;
        }, $this->tlsVersions);
    }

    /** @param array<array-key, TlsVersion|string> $versions 支持混用枚举和字符串，在启用 TLS 时校验版本。 */
    public function setTlsVersions(array $versions): void { $this->tlsVersions = $versions; }
}
