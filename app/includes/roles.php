<?php
/**
 * Administrator roles.
 *   admin   - everything.
 *   teacher - questions (add / edit / bulk upload / pictures / analysis), view results and the live monitor.
 *             A teacher can be limited to certain subjects (Settings > Administrators); a teacher with no
 *             subjects ticked can work on all subjects.
 */
const TEACHER_PAGES = [
    'question_portal.php', 'questions.php', 'question_edit.php', 'question_import.php', 'images.php',
    'image_upload.php', 'analysis.php', 'results.php', 'monitor.php', 'account.php', 'index.php', 'subjects.php', 'adminLogout.php',
];

function admin_role()
{
    return ($_SESSION['role'] ?? 'admin') === 'teacher' ? 'teacher' : 'admin';
}

function is_admin_role()
{
    return admin_role() === 'admin';
}

// Pages that only administrators may open.
function require_admin_role()
{
    if (!is_admin_role()) {
        deny_page();
    }
}

function deny_page()
{
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Not allowed</title>'
       . '<link rel="stylesheet" href="' . e(url('dist/css/bootstrap.min.css')) . '"></head><body style="padding:60px 20px;text-align:center;">'
       . '<h2>This page is for administrators</h2><p class="text-muted">Your teacher account can upload and edit questions, and view results.</p>'
       . '<a class="btn btn-primary" href="question_portal.php">Back to questions</a></body></html>';
    exit;
}

/** null = every subject; otherwise the list of subject ids this teacher may work on. */
function teacher_subject_ids()
{
    static $ids = false;
    if ($ids === false) {
        $ids = null;
        if (!is_admin_role()) {
            $rows = db_all("SELECT subject_id FROM teacher_subject WHERE admin_id = ?", 'i', (int) ($_SESSION['admin_id'] ?? 0));
            if ($rows) {
                $ids = array_map(function ($r) { return (int) $r['subject_id']; }, $rows);
            }
        }
    }
    return $ids;
}

function subject_allowed($subjectId)
{
    $ids = teacher_subject_ids();
    return $ids === null || in_array((int) $subjectId, $ids, true);
}

/** SQL fragment limiting a subject-id column to the teacher's subjects (ints only, so safe to inline). */
function subject_scope_sql($column)
{
    $ids = teacher_subject_ids();
    if ($ids === null) { return '1=1'; }
    return $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
}

function deny_subject()
{
    http_response_code(403);
    exit('You are not assigned to this subject.');
}
