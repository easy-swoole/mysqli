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
]);
