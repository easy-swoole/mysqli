<?php

/**
 * -- 新建测试用的库
 * CREATE DATABASE IF NOT EXISTS demo
 * DEFAULT CHARACTER SET utf8mb4
 * DEFAULT COLLATE utf8mb4_general_ci;
 *
 * -- 新建测试用的表
 * CREATE TABLE demo.user_test_list (
 *     name varchar(64) NULL,
 *     age TINYINT UNSIGNED NULL,
 *     addTime DATETIME NULL,
 *     state TINYINT UNSIGNED NULL
 * )
 * ENGINE=InnoDB
 * DEFAULT CHARSET=utf8mb4
 * COLLATE=utf8mb4_general_ci;
 */

defined("MYSQL_CONFIG") ?: define('MYSQL_CONFIG', [
    'host'          => '127.0.0.1',
    'port'          => 3306,
    'user'          => 'demo',
    'password'      => '123456',
    'database'      => 'demo',
    'timeout'       => 5,
    'charset'       => 'utf8mb4',
]);