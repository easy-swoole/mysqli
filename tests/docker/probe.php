<?php

declare(strict_types=1);

use EasySwoole\Mysqli\{Client, Config};

/** Validate the transport and server actually used, rather than trusting image names. */
function validateMatrixEnvironment(array $actual, string $version, bool $tls, bool $compress): void
{
    if (preg_match('/^' . preg_quote($version, '/') . '(?:[-+]|$)/', $actual['server_version']) !== 1) {
        throw new RuntimeException('Unexpected MySQL version: ' . $actual['server_version']);
    }
    $requiredModes = ['STRICT_TRANS_TABLES', 'ONLY_FULL_GROUP_BY', 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION'];
    if (array_diff($requiredModes, explode(',', strtoupper($actual['sql_mode']))) !== []) {
        throw new RuntimeException('The connection did not inherit the required strict SQL modes');
    }
    if ((int) $actual['max_allowed_packet'] < 64 * 1024 * 1024) {
        throw new RuntimeException('max_allowed_packet is too small for the large-parameter integration tests');
    }
    if ($actual['tls'] !== $tls || $actual['compression'] !== $compress) {
        throw new RuntimeException('TLS/compression was not negotiated as requested');
    }
    if ($tls && ($actual['tls_cipher'] === '' || !in_array($actual['tls_version'], ['TLSv1.2', 'TLSv1.3'], true))) {
        throw new RuntimeException('The TLS session did not report a supported protocol and cipher');
    }
    if (!$tls && ($actual['tls_cipher'] !== '' || $actual['tls_version'] !== '')) {
        throw new RuntimeException('The plaintext lane unexpectedly used TLS');
    }
}

if (in_array('--self-test', $argv, true)) {
    $sample = ['server_version' => '8.4.13', 'sql_mode' => 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
        'max_allowed_packet' => 1073741824, 'tls' => true, 'compression' => true,
        'tls_cipher' => 'TLS_AES_256_GCM_SHA384', 'tls_version' => 'TLSv1.3'];
    validateMatrixEnvironment($sample, '8.4.13', true, true);
    $plain = array_replace($sample, ['tls' => false, 'compression' => false, 'tls_cipher' => '', 'tls_version' => '']);
    validateMatrixEnvironment($plain, '8.4.13', false, false);
    foreach ([['server_version' => '8.4.130'], ['sql_mode' => 'STRICT_TRANS_TABLES'], ['max_allowed_packet' => 1024],
        ['tls' => false], ['compression' => false], ['tls_cipher' => ''], ['tls_version' => 'TLSv1.1']] as $invalid) {
        $rejected = false;
        try { validateMatrixEnvironment(array_replace($sample, $invalid), '8.4.13', true, true); }
        catch (RuntimeException $error) { $rejected = true; }
        if (!$rejected) { throw new RuntimeException('Environment guard accepted an invalid fixture'); }
    }
    echo "Environment guard self-test passed (2 valid, 7 rejected fixtures)", PHP_EOL;
    exit(0);
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    foreach (['MYSQLI_EXPECT_MYSQL_VERSION', 'MYSQLI_EXPECT_PHP_VERSION', 'MYSQLI_EXPECT_SWOOLE_VERSION', 'MYSQLI_MATRIX_TLS_MODE'] as $name) {
        if (getenv($name) === false || getenv($name) === '') { throw new RuntimeException('Missing matrix variable: ' . $name); }
    }
    if (PHP_VERSION !== getenv('MYSQLI_EXPECT_PHP_VERSION') || phpversion('swoole') !== getenv('MYSQLI_EXPECT_SWOOLE_VERSION')) {
        throw new RuntimeException('Unexpected PHP/Swoole runtime: ' . PHP_VERSION . '/' . phpversion('swoole'));
    }
    $tlsMode = getenv('MYSQLI_MATRIX_TLS_MODE');
    if (!in_array($tlsMode, ['disabled', 'verify_identity'], true) || MYSQL_CONFIG['tlsMode'] !== $tlsMode) {
        throw new RuntimeException('Invalid or mismatched matrix TLS mode');
    }
    if (MYSQL_CONFIG['host'] === '' || MYSQL_CONFIG['database'] !== 'mysqli_ci') {
        throw new RuntimeException('The matrix must use its dedicated mysqli_ci database');
    }
    Swoole\Coroutine\run(static function () use ($tlsMode): void {
        foreach ([false, true] as $compress) {
            $options = array_replace(MYSQL_CONFIG, ['compress' => $compress, 'timeout' => 30.0, 'maxConnectTime' => 10.0]);
            $client = new Client(new Config($options));
            try {
                $actual = $client->rawQuery('SELECT VERSION() AS server_version, @@SESSION.sql_mode AS sql_mode, @@GLOBAL.max_allowed_packet AS max_allowed_packet')[0];
                $status = array_column($client->rawQuery("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher', 'Ssl_version')"), 'Value', 'Variable_name');
                $actual['tls'] = $client->mysqlClient()->isTlsEnabled();
                $actual['compression'] = $client->mysqlClient()->isCompressionEnabled();
                $actual['tls_cipher'] = $status['Ssl_cipher'] ?? '';
                $actual['tls_version'] = $status['Ssl_version'] ?? '';
                validateMatrixEnvironment($actual, getenv('MYSQLI_EXPECT_MYSQL_VERSION'), $tlsMode === 'verify_identity', $compress);
                $statement = $client->prepare('SELECT ? AS label, ? AS flag');
                try {
                    if ($statement->execute(['矩阵😀', true]) !== [['label' => '矩阵😀', 'flag' => 1]]) {
                        throw new RuntimeException('Prepared transport smoke test returned unexpected values');
                    }
                } finally { $statement->close(); }
                $payload = $client->rawQuery("SELECT REPEAT('x', 65536) AS payload")[0]['payload'];
                if ($payload !== str_repeat('x', 65536)) { throw new RuntimeException('Transport payload was not preserved'); }
                $actual['php'] = PHP_VERSION;
                $actual['swoole'] = phpversion('swoole');
                echo json_encode($actual, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
            } finally { $client->close(); }
        }
    });
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
