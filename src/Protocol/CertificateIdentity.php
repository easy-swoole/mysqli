<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\Exception;

/** @internal Match a verified peer certificate to a DNS name or IP address. */
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
            // Never fall back to CN when a DNS subjectAltName is present.
            $names = $dnsNames !== [] ? $dnsNames : (array) ($info['subject']['commonName'] ?? []);
            foreach ($names as $name) {
                if (self::matchesDns($name, $expectedName)) { return; }
            }
        }
        throw new Exception('TLS certificate does not match the expected server identity');
    }

    /** Read SANs as DER bytes: formatted openssl_x509_parse text is ambiguous for commas in names. */
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

    /** Minimal bounded DER reader for the certificate's subjectAltName extension. */
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
        // A wildcard occupies the complete left-most label, matching exactly one label.
        if (!str_starts_with($pattern, '*.') || substr_count($pattern, '*') !== 1
            || substr_count($pattern, '.') < 2) { return false; }
        $dot = strpos($name, '.');
        return $dot !== false && $dot > 0 && substr($name, $dot) === substr($pattern, 1);
    }
}
