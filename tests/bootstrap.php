<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

define('MYSQL_CONFIG', [
    'host' => getenv('MYSQLI_TEST_HOST') ?: getenv('FAST_DB_TEST_HOST') ?: '',
    'port' => (int) (getenv('MYSQLI_TEST_PORT') ?: getenv('FAST_DB_TEST_PORT') ?: 3306),
    'user' => getenv('MYSQLI_TEST_USER') ?: getenv('FAST_DB_TEST_USER') ?: '',
    'password' => getenv('MYSQLI_TEST_PASSWORD') ?: getenv('FAST_DB_TEST_PASSWORD') ?: '',
    'database' => getenv('MYSQLI_TEST_DATABASE') ?: getenv('FAST_DB_TEST_DATABASE') ?: '',
    'timeout' => 1.0,
    'maxConnectTime' => 1.0,
    'charset' => 'utf8mb4',
    'tlsMode' => getenv('MYSQLI_TEST_TLS_MODE') ?: 'disabled',
    'tlsCaFile' => getenv('MYSQLI_TEST_TLS_CA_FILE') ?: '',
    'tlsCaPath' => getenv('MYSQLI_TEST_TLS_CA_PATH') ?: '',
    'tlsCertFile' => getenv('MYSQLI_TEST_TLS_CERT_FILE') ?: '',
    'tlsKeyFile' => getenv('MYSQLI_TEST_TLS_KEY_FILE') ?: '',
    'tlsKeyPassphrase' => getenv('MYSQLI_TEST_TLS_KEY_PASSPHRASE') ?: '',
    'tlsServerName' => getenv('MYSQLI_TEST_TLS_SERVER_NAME') ?: '',
]);
