<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\Exception;

/** @internal 将已通过验证的对端证书与 DNS 名称或 IP 地址匹配。 */
final class CertificateIdentity
{
    public static function verify(string $certificate, string $expectedName): void
    {
        $info = @openssl_x509_parse($certificate, false);
        if ($info === false || $expectedName === '' || str_contains($expectedName, "\0")) {
            throw new Exception('Invalid TLS certificate or server identity');
        }
        $ip = @inet_pton($expectedName);
        $dnsNames = [];
        foreach (self::subjectAltNames($certificate) as [$tag, $value]) {
            if ($tag === 0x87 && $ip !== false && $value === $ip) { return; }
            if ($tag === 0x82) { $dnsNames[] = $value; }
        }
        if ($ip === false) {
            // 存在 DNS subjectAltName 时，不回退到 CN。
            $names = $dnsNames !== [] ? $dnsNames : (array) ($info['subject']['commonName'] ?? []);
            foreach ($names as $name) {
                if (self::matchesDns($name, $expectedName)) { return; }
            }
        }
        throw new Exception('TLS certificate does not match the expected server identity');
    }

    /** 以 DER 字节读取 SAN；openssl_x509_parse 的格式化文本无法明确区分名称内的逗号。 */
    private static function subjectAltNames(string $certificate): array
    {
        if (!openssl_x509_export($certificate, $pem)) {
            throw new Exception('Invalid TLS certificate');
        }
        $der = base64_decode(preg_replace('/-----[^-]+-----|\\s+/', '', $pem), true);
        if ($der === false) { throw new Exception('Invalid TLS certificate encoding'); }
        $offset = 0;
        [, $outer] = self::element($der, $offset, 0x30);
        $offset = 0;
        [, $tbs] = self::element($outer, $offset, 0x30);
        $offset = 0;
        while ($offset < strlen($tbs)) {
            [$tag, $body] = self::element($tbs, $offset);
            if ($tag !== 0xa3) { continue; }
            $position = 0;
            [, $extensions] = self::element($body, $position, 0x30);
            $position = 0;
            while ($position < strlen($extensions)) {
                [, $extension] = self::element($extensions, $position, 0x30);
                $field = 0;
                [, $oid] = self::element($extension, $field, 0x06);
                if ($oid !== "\x55\x1d\x11") { continue; }
                [$valueTag, $value] = self::element($extension, $field);
                if ($valueTag === 0x01) { [$valueTag, $value] = self::element($extension, $field); }
                if ($valueTag !== 0x04) { throw new Exception('Invalid TLS subjectAltName extension'); }
                $field = 0;
                [, $names] = self::element($value, $field, 0x30);
                $field = 0;
                $result = [];
                while ($field < strlen($names)) { $result[] = self::element($names, $field); }
                return $result;
            }
        }
        return [];
    }

    /** 用于证书 subjectAltName 扩展的最小 DER 读取器，并校验读取边界。 */
    private static function element(string $der, int &$offset, ?int $expectedTag = null): array
    {
        if (strlen($der) - $offset < 2) { throw new Exception('Truncated TLS certificate'); }
        $tag = ord($der[$offset++]);
        $length = ord($der[$offset++]);
        if (($length & 0x80) !== 0) {
            $bytes = $length & 0x7f;
            if ($bytes === 0 || $bytes > 4 || strlen($der) - $offset < $bytes) {
                throw new Exception('Invalid TLS certificate length');
            }
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) { $length = ($length << 8) | ord($der[$offset++]); }
        }
        if (($expectedTag !== null && $tag !== $expectedTag) || $length > strlen($der) - $offset) {
            throw new Exception('Invalid TLS certificate element');
        }
        $body = substr($der, $offset, $length);
        $offset += $length;
        return [$tag, $body];
    }

    private static function matchesDns(string $pattern, string $name): bool
    {
        $pattern = strtolower(rtrim($pattern, '.'));
        $name = strtolower(rtrim($name, '.'));
        if (str_contains($pattern, "\0") || $pattern === '' || $name === '') { return false; }
        if (!str_contains($pattern, '*')) { return $pattern === $name; }
        // 通配符占据最左侧的完整标签，并且仅匹配一个标签。
        if (!str_starts_with($pattern, '*.') || substr_count($pattern, '*') !== 1
            || substr_count($pattern, '.') < 2) { return false; }
        $dot = strpos($name, '.');
        return $dot !== false && $dot > 0 && substr($name, $dot) === substr($pattern, 1);
    }
}
