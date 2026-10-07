<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Credentials are provided at runtime; no shared database fixtures are modified.
if (!defined('MYSQL_CONFIG')) {
    define('MYSQL_CONFIG', [
        'host' => getenv('MYSQLI_TEST_HOST') ?: getenv('FAST_DB_TEST_HOST') ?: '',
        'port' => (int)(getenv('MYSQLI_TEST_PORT') ?: getenv('FAST_DB_TEST_PORT') ?: 3306),
        'user' => getenv('MYSQLI_TEST_USER') ?: getenv('FAST_DB_TEST_USER') ?: '',
        'password' => getenv('MYSQLI_TEST_PASSWORD') ?: getenv('FAST_DB_TEST_PASSWORD') ?: '',
        'database' => getenv('MYSQLI_TEST_DATABASE') ?: getenv('FAST_DB_TEST_DATABASE') ?: '',
        'timeout' => 5,
        'charset' => 'utf8mb4',
    ]);
}
