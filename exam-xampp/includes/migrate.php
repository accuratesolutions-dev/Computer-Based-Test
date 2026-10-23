<?php
/**
 * Automatic, idempotent upgrades so an existing database keeps working after you copy new files.
 *   1. exam_question table  - the random question set each student gets
 *   2. subject.question_limit - optional "questions per student" (0 = every question)
 *   3. Junior class + JSS subjects (added once, only if no Junior class exists yet)
 * Called from ensure_migrated(); also by bin/init-db.php. Safe to run any number of times.
 */

// Default JSS subjects (Nigerian basic education curriculum). Edit later under "Subject Status".
const JUNIOR_CLASS_NAME = 'Junior Class';
const JUNIOR_CLASS_CODE = 'JS';
const JUNIOR_SUBJECTS = [
    'Mathematics', 'English Language', 'Physical and Health Education', 'Digital Tech', 'Intermediate Science',
    'Business Studies', 'Cultural and Creative Arts', 'Islamic Religious Studies', 'Christian Religious Studies',
    'Pre-Vocational Studies (PVS)', 'History', 'Basic Science and Tech. (BST1)', 'Computer & PHE (BST2)',
    'Social Citizenship', 'French Language', 'Yoruba Language',
];
// Subjects the first (v3) release created for Junior that are NOT in the school's list; removed once if unused.
const JUNIOR_OLD_DEFAULTS = [
    'English Studies', 'Basic Science', 'Basic Technology', 'Social Studies', 'Civic Education', 'Computer Studies',
    'Agricultural Science', 'Home Economics', 'Igbo Language', 'Hausa Language',
];
const MARKETING = 'Marketing';

function ensure_column($table, $column, $ddl)
{
    $r = db_one(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        'ss', $table, $column
    );
    if ((int) $r['c'] === 0) {
        db_exec("ALTER TABLE `$table` ADD COLUMN `$column` $ddl");
    }
}

function run_migrations()
{
    global $con;

    // One migrator at a time (two students/admins hitting a fresh install together).
    $locked = db_one("SELECT GET_LOCK('cbt_migrate', 10) AS l");
    try {
        db_exec(
            "CREATE TABLE IF NOT EXISTS `exam_question` (
               `id` bigint NOT NULL AUTO_INCREMENT,
               `student_id` int NOT NULL,
               `subject_id` int NOT NULL,
               `question_id` int NOT NULL,
               `position` int NOT NULL,
               PRIMARY KEY (`id`),
               UNIQUE KEY `uq_exam_question` (`student_id`,`question_id`),
               KEY `idx_exam_question_set` (`student_id`,`subject_id`,`position`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        db_exec("CREATE TABLE IF NOT EXISTS app_meta (k VARCHAR(50) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL)");

        $col = db_one(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject' AND COLUMN_NAME = 'question_limit'"
        );
        if ((int) $col['c'] === 0) {
            db_exec("ALTER TABLE `subject` ADD COLUMN `question_limit` int NOT NULL DEFAULT 0 COMMENT 'questions drawn per student; 0 = all'");
        }

        // v4 columns: scheduled opening, topic mix, option shuffle, question topic, per-student option order
        ensure_column('subject', 'open_at', 'DATETIME NULL');
        ensure_column('subject', 'close_at', 'DATETIME NULL');
        ensure_column('subject', 'question_mix', 'TEXT NULL');
        ensure_column('subject', 'shuffle_options', 'TINYINT NOT NULL DEFAULT 0');
        ensure_column('question', 'topic', 'VARCHAR(100) NULL');
        ensure_column('dosubject', 'paused_at', 'DATETIME NULL');              // invigilator pause
        ensure_column('dosubject', 'extra_seconds', 'INT NOT NULL DEFAULT 0'); // time given back after a pause / added by the invigilator
        ensure_column('admin', 'role', "VARCHAR(20) NOT NULL DEFAULT 'admin'");
        db_exec("CREATE TABLE IF NOT EXISTS teacher_subject (admin_id INT NOT NULL, subject_id INT NOT NULL, PRIMARY KEY (admin_id, subject_id))");
        ensure_column('question', 'level', "VARCHAR(20) NOT NULL DEFAULT ''"); // '' = every level of the class
        ensure_column('exam_question', 'option_order', "VARCHAR(20) NOT NULL DEFAULT ''");

        // Only for an existing installation: a brand-new empty database gets its classes (incl. Junior) from seed.sql.
        $anyClass = db_one("SELECT COUNT(*) AS c FROM class");
        $junior = db_one("SELECT id FROM class WHERE UPPER(sortname) = ? OR class LIKE '%Junior%' LIMIT 1", 's', JUNIOR_CLASS_CODE);
        if (!$junior && (int) $anyClass['c'] > 0) {
            db_exec("INSERT INTO class (class, sortname) VALUES (?, ?)", 'ss', JUNIOR_CLASS_NAME, JUNIOR_CLASS_CODE);
            $classId = db_insert_id();
            foreach (JUNIOR_SUBJECTS as $name) {
                db_exec("INSERT IGNORE INTO subject (class_id, sub_name) VALUES (?, ?)", 'is', $classId, $name);
            }
        }

        $anyClass = db_one("SELECT COUNT(*) AS c FROM class");
        if ((int) $anyClass['c'] > 0) {
            migrate_subject_updates();
        }
        db_exec("INSERT IGNORE INTO app_meta (k, v) VALUES ('schema_v4', '1')");
        db_exec("INSERT IGNORE INTO app_meta (k, v) VALUES ('schema_v5', '1')");
        db_exec("INSERT IGNORE INTO app_meta (k, v) VALUES ('schema_v6', '1')");
    } finally {
        if ($locked && (int) $locked['l'] === 1) {
            db_one("SELECT RELEASE_LOCK('cbt_migrate') AS r");
        }
    }
}

// One-time subject updates (each recorded in app_meta, so a subject you delete later is never re-added).
function migrate_subject_updates()
{
    if (!db_one("SELECT k FROM app_meta WHERE k = 'junior_subjects_v2'")) {
        $junior = db_one("SELECT id FROM class WHERE UPPER(sortname) = ? OR class LIKE '%Junior%' ORDER BY id LIMIT 1", 's', JUNIOR_CLASS_CODE);
        if ($junior) {
            $cid = (int) $junior['id'];
            foreach (JUNIOR_SUBJECTS as $name) {
                db_exec("INSERT IGNORE INTO subject (class_id, sub_name) VALUES (?, ?)", 'is', $cid, $name); // existing ones are skipped
            }
            // Drop the old defaults that are not in the school's list, unless anything already uses them.
            foreach (JUNIOR_OLD_DEFAULTS as $name) {
                $name = trim($name);
                if (in_array($name, JUNIOR_SUBJECTS, true)) {
                    continue;
                }
                $row = db_one("SELECT sub_id FROM subject WHERE class_id = ? AND TRIM(sub_name) = ?", 'is', $cid, $name);
                if (!$row) {
                    continue;
                }
                $sid = (int) $row['sub_id'];
                $used = (int) db_one("SELECT COUNT(*) AS c FROM question WHERE subject_id = ?", 'i', $sid)['c']
                      + (int) db_one("SELECT COUNT(*) AS c FROM exam_result WHERE subject_id = ?", 'i', $sid)['c']
                      + (int) db_one("SELECT COUNT(*) AS c FROM dosubject WHERE dosub_id = ?", 'i', $sid)['c']
                      + (int) db_one(
                            "SELECT COUNT(*) AS c FROM register WHERE department = ? AND ? IN (TRIM(subject1),TRIM(subject2),TRIM(subject3),TRIM(subject4),TRIM(subject5),TRIM(subject6),TRIM(subject7),TRIM(subject8),TRIM(subject9))",
                            'ss', (string) $cid, $name
                        )['c'];
                if ($used === 0) {
                    db_exec("DELETE FROM subject WHERE sub_id = ?", 'i', $sid);
                }
            }
        }
        db_exec("INSERT IGNORE INTO app_meta (k, v) VALUES ('junior_subjects_v2', '1')");
    }
    if (!db_one("SELECT k FROM app_meta WHERE k = 'marketing_v1'")) {
        foreach (db_all("SELECT id, class, sortname FROM class") as $c) {
            if (!class_is_junior($c)) {
                db_exec("INSERT IGNORE INTO subject (class_id, sub_name) VALUES (?, ?)", 'is', (int) $c['id'], MARKETING);
            }
        }
        db_exec("INSERT IGNORE INTO app_meta (k, v) VALUES ('marketing_v1', '1')");
    }
}

// Cheap check (two small queries); runs the full upgrade only when something is missing.
function ensure_migrated()
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $t = db_one("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_question'");
    $c = db_one("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subject' AND COLUMN_NAME = 'question_limit'");
    $j = db_one("SELECT COUNT(*) AS c FROM class WHERE UPPER(sortname) = ? OR class LIKE '%Junior%'", 's', JUNIOR_CLASS_CODE);
    $any = db_one("SELECT COUNT(*) AS c FROM class");
    $m = db_one("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_meta'");
    $ts = db_one("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teacher_subject'");
    $pending = (int) $t['c'] === 0 || (int) $c['c'] === 0 || (int) $m['c'] === 0 || (int) $ts['c'] === 0
        || ((int) $j['c'] === 0 && (int) $any['c'] > 0);
    if (!$pending && (int) $any['c'] > 0) {
        $done = db_one("SELECT COUNT(*) AS c FROM app_meta WHERE k IN ('junior_subjects_v2', 'marketing_v1', 'schema_v4', 'schema_v5', 'schema_v6')");
        $pending = (int) $done['c'] < 5;
    }
    if ($pending) {
        run_migrations();
    }
}
