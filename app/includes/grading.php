<?php
/**
 * Server-side grading. Correct answers never leave the database.
 * Idempotent: a subject can only be graded once per student.
 *
 * Answers come from two places: the auto-saved rows in exam_answer, and (when the
 * submission arrives in time) the submitted form, which wins on conflict. If time
 * had already run out, only the auto-saved answers count.
 */
require_once __DIR__ . '/functions.php';

function saved_answers($studentId, $subjectId)
{
    $map = [];
    foreach (db_all("SELECT question_id, choice FROM exam_answer WHERE student_id = ? AND subject_id = ?", 'ii', (int) $studentId, (int) $subjectId) as $r) {
        $map[(int) $r['question_id']] = (string) $r['choice'];
    }
    return $map;
}

/** Fisher-Yates shuffle with a cryptographically secure generator. */
function secure_shuffle(array $items)
{
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    return $items;
}

/**
 * Gives a student their own random question set for a subject, once per attempt.
 * The set (and its order) is stored, so a refresh, crash or re-login shows exactly the same
 * questions in the same order. If the subject has a "questions per student" limit, that many
 * are drawn at random from the whole question bank.
 */
function assign_exam_questions($studentId, array $subject)
{
    $studentId = (int) $studentId;
    $subId = (int) $subject['sub_id'];
    $lock = 'cbt_q_' . $studentId . '_' . $subId;
    db_one("SELECT GET_LOCK(?, 10) AS l", 's', $lock);
    try {
        $have = db_one("SELECT COUNT(*) AS c FROM exam_question WHERE student_id = ? AND subject_id = ?", 'ii', $studentId, $subId);
        if ((int) $have['c'] === 0) {
            $bank = db_all(
                "SELECT id, topic, option1, option2, option3, option4 FROM question WHERE class_id = ? AND subject_id = ?",
                'ii', (int) $subject['class_id'], $subId
            );
            $byId = [];
            foreach ($bank as $q) { $byId[(int) $q['id']] = $q; }

            $ids = [];
            $mix = json_decode((string) ($subject['question_mix'] ?? ''), true);
            if (is_array($mix) && $mix) {
                // Topic mix: draw the requested number from each topic, then mix them all together.
                $byTopic = [];
                foreach ($bank as $q) { $byTopic[trim((string) $q['topic'])][] = (int) $q['id']; }
                foreach ($mix as $topic => $n) {
                    $pool = secure_shuffle($byTopic[(string) $topic] ?? []);
                    $ids = array_merge($ids, array_slice($pool, 0, max(0, (int) $n)));
                }
                $ids = secure_shuffle($ids);
            }
            if (!$ids) {
                $ids = secure_shuffle(array_keys($byId));
                $limit = (int) ($subject['question_limit'] ?? 0);
                if ($limit > 0 && $limit < count($ids)) {
                    $ids = array_slice($ids, 0, $limit);
                }
            }

            $shuffleOptions = !empty($subject['shuffle_options']);
            foreach (array_chunk($ids, 200, true) as $chunk) {
                $marks = [];
                $params = [];
                foreach ($chunk as $pos => $qid) {
                    $order = '';
                    $q = $byId[$qid];
                    if ($shuffleOptions && !options_are_positional([$q['option1'], $q['option2'], $q['option3'], $q['option4']])) {
                        $order = implode(',', secure_shuffle([1, 2, 3, 4]));
                    }
                    $marks[] = '(?, ?, ?, ?, ?)';
                    array_push($params, $studentId, $subId, $qid, $pos + 1, $order);
                }
                db_exec(
                    "INSERT IGNORE INTO exam_question (student_id, subject_id, question_id, position, option_order) VALUES " . implode(',', $marks),
                    str_repeat('iiiis', count($chunk)), ...$params
                );
            }
        }
    } finally {
        db_one("SELECT RELEASE_LOCK(?) AS r", 's', $lock);
    }
}

/** The student's assigned questions in their own order. Falls back to the whole bank for attempts that predate this feature. */
function exam_questions($studentId, array $subject, $columns = 'q.id, q.question_desc, q.option1, q.option2, q.option3, q.option4')
{
    $rows = db_all(
        "SELECT $columns, e.option_order FROM exam_question e JOIN question q ON q.id = e.question_id
          WHERE e.student_id = ? AND e.subject_id = ? ORDER BY e.position",
        'ii', (int) $studentId, (int) $subject['sub_id']
    );
    if ($rows) {
        return $rows;
    }
    $assigned = db_one("SELECT COUNT(*) AS c FROM exam_question WHERE student_id = ? AND subject_id = ?", 'ii', (int) $studentId, (int) $subject['sub_id']);
    if ((int) $assigned['c'] > 0) {
        return []; // every assigned question was deleted since
    }
    return db_all(
        "SELECT $columns, '' AS option_order FROM question q WHERE q.class_id = ? AND q.subject_id = ? ORDER BY q.id",
        'ii', (int) $subject['class_id'], (int) $subject['sub_id']
    );
}

function finalize_exam($studentId, array $subject, array $answers, $withinTime = true)
{
    global $con;
    $studentId = (int) $studentId;
    $subId = (int) $subject['sub_id'];
    $classId = (int) $subject['class_id'];

    $existing = db_one("SELECT * FROM exam_result WHERE student_id = ? AND subject_id = ?", 'ii', $studentId, $subId);
    if ($existing) {
        return $existing;
    }

    $questions = exam_questions($studentId, $subject, 'q.id, q.option1, q.option2, q.option3, q.option4, q.true_answer');
    $saved = saved_answers($studentId, $subId);
    $total = count($questions);
    $correct = 0;

    foreach ($questions as $q) {
        $picked = $saved[(int) $q['id']] ?? null;
        if ($withinTime && isset($answers['que_' . $q['id']])) {
            $picked = $answers['que_' . $q['id']];
        }
        if (!is_string($picked) || !in_array($picked, ['1', '2', '3', '4'], true)) {
            continue;
        }
        $pickedText = trim((string) $q['option' . $picked]);
        if (strcasecmp($pickedText, trim((string) $q['true_answer'])) === 0) {
            $correct++;
        }
    }

    $percent = $total > 0 ? round(($correct / $total) * 100, 2) : 0;

    mysqli_begin_transaction($con);
    try {
        db_exec(
            "INSERT IGNORE INTO exam_result (student_id, subject_id, class_id, correct, total, percent)
             VALUES (?, ?, ?, ?, ?, ?)",
            'iiiiid', $studentId, $subId, $classId, $correct, $total, $percent
        );
        db_exec(
            "UPDATE dosubject SET istaken = 1 WHERE dostd_id = ? AND doclass_id = ? AND dosub_id = ?",
            'iii', $studentId, $classId, $subId
        );
        mysqli_commit($con);
    } catch (Throwable $t) {
        mysqli_rollback($con);
        throw $t;
    }

    return db_one("SELECT * FROM exam_result WHERE student_id = ? AND subject_id = ?", 'ii', $studentId, $subId);
}

/**
 * Grades every attempt whose time ran out but was never submitted (browser closed,
 * power cut...) using the auto-saved answers. Safe to call repeatedly.
 * Returns the number of attempts finalized.
 */
function finalize_expired_attempts()
{
    $n = 0;
    foreach (db_all("SELECT * FROM dosubject WHERE istaken = 0") as $a) {
        $endsAt = strtotime($a['time']) + ((int) $a['duration'] * 60) + 60; // same grace as result.php
        if (time() <= $endsAt) {
            continue;
        }
        $subject = db_one("SELECT * FROM subject WHERE sub_id = ?", 'i', (int) $a['dosub_id']);
        if ($subject) {
            finalize_exam((int) $a['dostd_id'], $subject, [], false);
            $n++;
        }
    }
    return $n;
}
