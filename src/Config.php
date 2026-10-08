<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

final class Config
{
    private string $host = '127.0.0.1';
    private string $user = '';
    private string $password = '';
    private string $database = '';
    private int $port = 3306;
    private float $timeout = 3.0;
    private string $charset = 'utf8mb4';
    private float $maxConnectTime = 3.0;
    private bool $compress = false;

    public function __construct(array $config = [])
    {
        foreach ($config as $name => $value) {
            $setter = 'set' . ucfirst((string) $name);
            if (method_exists($this, $setter)) {
                $this->{$setter}($value);
            }
        }
        if (isset($config['timeout']) && !isset($config['maxConnectTime'])) {
            $this->maxConnectTime = (float) $config['timeout'];
        }
        if (isset($config['maxConnectTim']) && !isset($config['maxConnectTime'])) {
            $this->maxConnectTime = (float) $config['maxConnectTim'];
        }
    }

    public function getHost(): string { return $this->host; }
    public function setHost(string $host): void { $this->host = $host; }
    public function getUser(): string { return $this->user; }
    public function setUser(string $user): void { $this->user = $user; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }
    public function getDatabase(): string { return $this->database; }
    public function setDatabase(string $database): void { $this->database = $database; }
    public function getPort(): int { return $this->port; }
    public function setPort(int $port): void { $this->port = $port; }
    public function getTimeout(): float { return $this->timeout; }
    public function setTimeout(float $timeout): void { $this->timeout = $this->positive($timeout, 'timeout'); }
    public function getCharset(): string { return $this->charset; }
    public function setCharset(string $charset): void { $this->charset = $charset; }
    public function getMaxConnectTime(): float { return $this->maxConnectTime; }
    public function setMaxConnectTime(float $timeout): void { $this->maxConnectTime = $this->positive($timeout, 'maxConnectTime'); }
    public function isCompress(): bool { return $this->compress; }
    public function setCompress(bool $compress): void { $this->compress = $compress; }

    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'user' => $this->user,
            'password' => $this->password,
            'database' => $this->database,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'charset' => $this->charset,
            'maxConnectTime' => $this->maxConnectTime,
            'compress' => $this->compress,
        ];
    }

    private function positive(float $value, string $name): float
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException("{$name} must be greater than zero");
        }
        return $value;
    }
}
