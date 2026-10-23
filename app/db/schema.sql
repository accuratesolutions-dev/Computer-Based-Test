-- CBT Software: clean schema (tables only; starter data lives in seed.sql) (MySQL 8 / MariaDB 10.4+, strict-mode safe).
-- Contains NO student data and NO admin accounts; create the first admin with
-- bin/create-admin.php (or set ADMIN_USER / ADMIN_PASSWORD for the Docker setup).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `admin` (
  `id` int NOT NULL AUTO_INCREMENT,
  `Username` varchar(50) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'admin' COMMENT 'admin or teacher',
  `RegDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`Username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `class` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class` varchar(255) NOT NULL,
  `sortname` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `subject` (
  `sub_id` int NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `sub_name` varchar(255) NOT NULL,
  `sub_duration` int NOT NULL DEFAULT 0 COMMENT 'minutes; 0 = not scheduled',
  `sub_regdate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(20) NOT NULL DEFAULT 'Disabled',
  `question_limit` int NOT NULL DEFAULT 0 COMMENT 'questions drawn per student; 0 = all',
  `open_at` datetime NULL,
  `close_at` datetime NULL,
  `question_mix` text NULL,
  `shuffle_options` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`sub_id`),
  UNIQUE KEY `uq_subject_class_name` (`class_id`,`sub_name`),
  CONSTRAINT `fk_subject_class` FOREIGN KEY (`class_id`) REFERENCES `class` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `instruction` (
  `id` int NOT NULL AUTO_INCREMENT,
  `text` text NOT NULL,
  `date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `question` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `question_desc` text NOT NULL,
  `option1` text NOT NULL,
  `option2` text NOT NULL,
  `option3` text NOT NULL,
  `option4` text NOT NULL,
  `true_answer` text NOT NULL COMMENT 'text of the correct option',
  `topic` varchar(100) NULL,
  `level` varchar(20) NOT NULL DEFAULT '' COMMENT 'J.S.S 1 / S.S.S 2 ...; empty = every level of the class',
  `uploaded_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_question_class_subject` (`class_id`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `register` (
  `id` int NOT NULL AUTO_INCREMENT,
  `surname` varchar(300) NOT NULL,
  `otherNames` varchar(300) NOT NULL,
  `gender` varchar(20) NOT NULL,
  `phoneNumber` varchar(30) NOT NULL,
  `DateOfBirth` varchar(20) NOT NULL,
  `department` int NOT NULL,
  `level` varchar(20) NOT NULL,
  `passport` varchar(300) NOT NULL DEFAULT '',
  `subject1` varchar(255) NOT NULL DEFAULT '',
  `subject2` varchar(255) NOT NULL DEFAULT '',
  `subject3` varchar(255) NOT NULL DEFAULT '',
  `subject4` varchar(255) NOT NULL DEFAULT '',
  `subject5` varchar(255) NOT NULL DEFAULT '',
  `subject6` varchar(255) NOT NULL DEFAULT '',
  `subject7` varchar(255) NOT NULL DEFAULT '',
  `subject8` varchar(255) NOT NULL DEFAULT '',
  `subject9` varchar(255) NOT NULL DEFAULT '',
  `examNumber` varchar(50) NOT NULL,
  `regDate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_register_exam_number` (`examNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per started attempt; the server clock enforces the time limit.
CREATE TABLE IF NOT EXISTS `dosubject` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dostd_id` int NOT NULL,
  `doclass_id` int NOT NULL,
  `dosub_id` int NOT NULL,
  `time` datetime NOT NULL COMMENT 'attempt start (app timezone)',
  `duration` int NOT NULL COMMENT 'minutes',
  `istaken` tinyint NOT NULL DEFAULT 0,
  `paused_at` datetime NULL COMMENT 'set while the invigilator has paused this student',
  `extra_seconds` int NOT NULL DEFAULT 0 COMMENT 'time given back after pauses or added by the invigilator',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attempt` (`dostd_id`,`dosub_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One graded result per student per subject.
CREATE TABLE IF NOT EXISTS `exam_result` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `class_id` int NOT NULL,
  `correct` int NOT NULL DEFAULT 0,
  `total` int NOT NULL DEFAULT 0,
  `percent` decimal(5,2) NOT NULL DEFAULT 0,
  `submitted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_result` (`student_id`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `bucket` varchar(20) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts` (`ip`,`bucket`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auto-saved answers while an exam is in progress (one row per student per question).
CREATE TABLE IF NOT EXISTS `exam_answer` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `question_id` int NOT NULL,
  `choice` tinyint NOT NULL COMMENT '1-4 (option position)',
  `saved_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_answer` (`student_id`,`question_id`),
  KEY `idx_answer_subject` (`student_id`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The random question set (and order) each student gets for a subject; created when they start it.
CREATE TABLE IF NOT EXISTS `exam_question` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `subject_id` int NOT NULL,
  `question_id` int NOT NULL,
  `position` int NOT NULL,
  `option_order` varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exam_question` (`student_id`,`question_id`),
  KEY `idx_exam_question_set` (`student_id`,`subject_id`,`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One-time upgrade markers (so subjects you delete later are never re-added by an update).
CREATE TABLE IF NOT EXISTS `app_meta` (
  `k` varchar(50) NOT NULL,
  `v` varchar(255) NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which subjects a teacher account may work on (no rows = all subjects).
CREATE TABLE IF NOT EXISTS `teacher_subject` (
  `admin_id` int NOT NULL,
  `subject_id` int NOT NULL,
  PRIMARY KEY (`admin_id`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
