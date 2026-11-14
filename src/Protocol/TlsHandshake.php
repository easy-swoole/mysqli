<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\TimeoutException;
use Swoole\Coroutine\Client as SocketClient;
use Swoole\Coroutine\Socket;

/** @internal 升级已发送 SSLRequest 的 TCP 连接，负责握手截止时间与证书校验。 */
final class TlsHandshake
{
    /** @param callable(float, int, string): Exception $ioFailure */
    public static function enableWithOptions(Socket $socket, SocketClient $client, array $options, Deadline $deadline, callable $ioFailure, ?string $expectedName): void
    {
        // SWOOLE_51_COMPAT：5.1 在握手时才创建 SSL_CTX，且仅在开启验证时加载 CA。
        // 因此先保存配置，再由 Client::enableSSL() 初始化上下文并提供原生握手错误码。
        // 此处移除 open_ssl 是 enableSSL() 的调用要求，在 6.2 中也不能单独删除。
        // 停止支持 5.1 后的清理边界，见 README 的“Swoole 版本兼容与清理”。
        unset($options['open_ssl']);
        if (!$client->set($options)) {
            throw new Exception('Unable to configure MySQL TLS');
        }
        // SWOOLE_SNI_COMPAT：按验证模式和 SNI 判断；这不是 Swoole 版本号判断。
        $verifyAfterHandshake = $options['ssl_verify_peer'] && $options['ssl_host_name'] !== '';
        if ($verifyAfterHandshake && !method_exists(SocketClient::class, 'verifyPeerCert')) {
            throw new Exception('Unable to configure separate TLS certificate verification');
        }
        $remaining = $deadline->remaining();
        $seconds = (int) $remaining;
        if (!$socket->setOption(SOL_SOCKET, SO_RCVTIMEO, ['sec' => $seconds, 'usec' => max(1, (int) (($remaining - $seconds) * 1_000_000))])) {
            throw new Exception('Unable to configure TLS handshake timeout');
        }
        $expired = false;
        // 固定总截止时间，避免对端通过持续推进多步握手而无限延长连接过程。
        $timer = \Swoole\Timer::after(max(1, (int) ceil($remaining * 1000)), static function () use ($socket, &$expired): void {
            $expired = true;
            $socket->cancel(SWOOLE_EVENT_READ);
            $socket->cancel(SWOOLE_EVENT_WRITE);
        });
        if ($timer === false) { throw new Exception('Unable to schedule TLS handshake deadline'); }
        try {
            // SWOOLE_51_COMPAT：必须保持验证开启，让 5.1 在创建 SSL_CTX 时加载 CA。
            $certificate = null;
            $ok = @$client->enableSSL();
            if ($expired) { throw new TimeoutException('MySQL TLS handshake timed out', 110); }
            if (!$ok && $verifyAfterHandshake && $client->errCode === SWOOLE_ERROR_SSL_VERIFY_FAILED) {
                $deadline->remaining();
                // SWOOLE_SNI_COMPAT：5.1.8 和 6.2.4 都会根据 SNI 执行原生主机名检查。
                // 仅在证书存在且原生完整链复核通过后完成 TLS 状态，再独立校验身份。
                // 这不是仅供 5.1 使用的分支；停止支持 5.1 时不能直接删除。
                $certificate = self::verifyCertificate($socket, $client);
                $ok = @$socket->sslHandshake();
                if ($expired) { throw new TimeoutException('MySQL TLS handshake timed out', 110); }
            }
            if (!$ok) { throw $ioFailure($remaining, $client->errCode, $client->errMsg); }
            $deadline->remaining();
            if ($verifyAfterHandshake && $certificate === null) {
                $certificate = self::verifyCertificate($socket, $client);
            }
            if ($expectedName !== null) {
                $certificate ??= $client->getPeerCert();
                if ($certificate === false) { throw new Exception('TLS peer certificate is unavailable'); }
                CertificateIdentity::verify($certificate, $expectedName);
            }
            $deadline->remaining();
        } finally {
            if (\Swoole\Timer::exists($timer)) { \Swoole\Timer::clear($timer); }
        }
    }

    /** 独立于 SNI 复核原生证书链，保留服务端提供的中间证书参与验证。 */
    private static function verifyCertificate(Socket $socket, SocketClient $client): string
    {
        // SWOOLE_SNI_COMPAT：此时清除上下文中的主机名，不会改变已经发送的 SNI。
        if (!$socket->setProtocol(['ssl_verify_host' => false, 'ssl_host_name' => ''])
            || ($certificate = $client->getPeerCert()) === false || !@$client->verifyPeerCert(false)) {
            throw new Exception('MySQL TLS certificate verification failed');
        }
        return $certificate;
    }
}
