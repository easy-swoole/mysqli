CREATE TABLE IF NOT EXISTS `easyswoole_user`
(
    `id`      int unsigned NOT NULL AUTO_INCREMENT COMMENT 'increment id',
    `name`    varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'name',
    `status`  tinyint unsigned DEFAULT '0' COMMENT 'status',
    `score`   int unsigned DEFAULT '0' COMMENT 'score',
    `sex`     tinyint unsigned DEFAULT '0' COMMENT 'sex',
    `address` json                                                          DEFAULT NULL COMMENT 'address',
    `email`   varchar(150) COLLATE utf8mb4_general_ci                       DEFAULT NULL COMMENT 'email',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
