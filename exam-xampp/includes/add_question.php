<?php
/**
 * Handles the "upload a question" form on admin/question_portal.php.
 * Included only by pages that have passed admin_session.php. Uses post-redirect-get, so a refresh
 * never re-submits, and the class + subject stay selected for the next question.
 */
require_once __DIR__ . '/admin_layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn-submit'])) {
    if (!csrf_check()) {
        http_response_code(403);
        exit('Invalid or expired form token. Please reload the page and try again.');
    }
    $classId   = (int) ($_POST['classGroup'] ?? 0);
    $subjectId = (int) ($_POST['classSubject'] ?? 0);
    $desc      = safe_html($_POST['questionDescription'] ?? '');
    $opts = [];
    for ($i = 1; $i <= 4; $i++) {
        $opts[$i] = trim(strip_tags((string) ($_POST['option' . $i] ?? '')));
    }
    $correct = (int) ($_POST['trueAnswer'] ?? 0);
    $topic   = trim(strip_tags((string) ($_POST['topic'] ?? '')));
    $levelIn = (string) ($_POST['level'] ?? '');   // 'all' = every level of the class
    $validLevels = levels_for_class($classId);
    $level   = $levelIn === 'all' ? '' : (in_array($levelIn, $validLevels, true) ? $levelIn : null);

    $back = 'question_portal.php?class=' . $classId . '&subject=' . $subjectId . '&level=' . rawurlencode($levelIn);
    if (!db_one("SELECT sub_id FROM subject WHERE sub_id = ? AND class_id = ?", 'ii', $subjectId, $classId)) {
        flash_set('danger', 'Please choose a valid class and subject.');
        $back = 'question_portal.php';
    } elseif ($level === null) {
        flash_set('danger', 'Please choose the level (e.g. ' . $validLevels[0] . ') or "All levels".');
    } elseif (trim(strip_tags($desc, '<img>')) === '') {
        flash_set('danger', 'The question cannot be empty.');
    } elseif (in_array('', $opts, true)) {
        flash_set('danger', 'All four options are required.');
    } elseif ($correct < 1 || $correct > 4) {
        flash_set('danger', 'Please select which option is correct.');
    } else {
        db_exec(
            "INSERT INTO question (class_id, subject_id, question_desc, option1, option2, option3, option4, true_answer, topic, level)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'iissssssss', $classId, $subjectId, $desc, $opts[1], $opts[2], $opts[3], $opts[4], $opts[$correct],
            $topic !== '' ? mb_substr($topic, 0, 100) : null, $level
        );
        $n = (int) db_one("SELECT COUNT(*) AS c FROM question WHERE subject_id = ?", 'i', $subjectId)['c'];
        flash_set('success', 'Question uploaded for ' . ($level === '' ? 'all levels' : $level) . "! This subject now has $n question(s). Add the next one below.");
    }
    header('Location: ' . $back);
    exit;
}
