CREATE TABLE IF NOT EXISTS `student_info`
(
    `id`         int                                                           NOT NULL AUTO_INCREMENT,
    `studentId`  int unsigned NOT NULL,
    `address`    json                                                                   DEFAULT NULL,
    `note`       varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
    `sex`        tinyint unsigned NOT NULL DEFAULT '0',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
