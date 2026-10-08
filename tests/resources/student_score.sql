CREATE TABLE IF NOT EXISTS `student_score`
(
    `score_id`   int                                                           NOT NULL AUTO_INCREMENT,
    `student_id` int unsigned NOT NULL,
    `course_id`  int unsigned NOT NULL,
    `score`      int unsigned NOT NULL,
    `extra_mark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
    PRIMARY KEY (`score_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
