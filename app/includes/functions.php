<?php
/**
 * Shared helpers: escaping, prepared-statement shortcuts, CSRF, auth redirects.
 * Requires config.php to have been loaded (it provides $con).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/levels.php';
require_once __DIR__ . '/settings.php';

/* ---------- output / url helpers ---------- */

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Escaped echo with trailing spacing (kept for the existing templates).
function output($value)
{
    echo e($value) . '  ';
}

function outputTwo($val1, $val2)
{
    echo e($val1) . '  ' . e($val2);
}

function url($path = '')
{
    return APP_BASE_PATH . '/' . ltrim($path, '/');
}

function redirect($path)
{
    header('Location: ' . url($path));
    exit;
}

function client_ip()
{
    if (env('TRUST_PROXY', '0') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* ---------- prepared statement shortcuts ---------- */

function db_query($sql, $types = '', ...$params)
{
    global $con;
    $stmt = mysqli_prepare($con, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return $stmt;
}

function db_all($sql, $types = '', ...$params)
{
    $stmt = db_query($sql, $types, ...$params);
    $res = mysqli_stmt_get_result($stmt);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

function db_one($sql, $types = '', ...$params)
{
    $rows = db_all($sql, $types, ...$params);
    return $rows ? $rows[0] : null;
}

function db_exec($sql, $types = '', ...$params)
{
    global $con;
    $stmt = db_query($sql, $types, ...$params);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected;
}

function db_insert_id()
{
    global $con;
    return mysqli_insert_id($con);
}

function db_escape($value)
{
    global $con;
    return mysqli_real_escape_string($con, (string) $value);
}

/* ---------- sessions & CSRF ---------- */

function start_secure_session()
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || env('FORCE_SECURE_COOKIES', '0') === '1';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token()
{
    start_secure_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check()
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/* ---------- login throttling (per IP, DB-backed) ---------- */

function login_blocked($bucket = 'student')
{
    $row = db_one(
        "SELECT COUNT(*) AS c FROM login_attempts
         WHERE ip = ? AND bucket = ? AND attempted_at > (NOW() - INTERVAL 10 MINUTE)",
        'ss', client_ip(), $bucket
    );
    return ((int) $row['c']) >= 10;
}

function login_failed($bucket = 'student')
{
    db_exec("INSERT INTO login_attempts (ip, bucket) VALUES (?, ?)", 'ss', client_ip(), $bucket);
}

function login_cleared($bucket = 'student')
{
    db_exec("DELETE FROM login_attempts WHERE ip = ? AND bucket = ?", 'ss', client_ip(), $bucket);
}

/* ---------- domain helpers ---------- */

function getAllInstruction()
{
    return db_all("SELECT * FROM instruction ORDER BY id ASC");
}

function getDepartment($value)
{
    $row = db_one("SELECT class FROM class WHERE id = ?", 'i', (int) $value);
    echo $row ? e($row['class']) : '';
}

// Subject names are stored on the student record; resolve to the subject row.
function findSubject($classId, $subjectName)
{
    return db_one(
        "SELECT * FROM subject WHERE class_id = ? AND TRIM(sub_name) = TRIM(?) LIMIT 1",
        'is', (int) $classId, (string) $subjectName
    );
}

// Public URL for a stored passport path (supports legacy "../images/..." values).
function passport_url($path)
{
    $path = preg_replace('#^(\.\./)+#', '', (string) $path);
    if ($path === '') {
        $path = 'images/students avatar.jpg';
    }
    return url('onlineForm/' . implode('/', array_map('rawurlencode', explode('/', $path))));
}

// Light sanitiser for admin-authored (CKEditor) question HTML.
function safe_html($html)
{
    $html = strip_tags((string) $html, '<p><br><b><strong><i><em><u><sub><sup><ul><ol><li><img><table><thead><tbody><tr><td><th><span><div><h1><h2><h3><h4>');
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', '', $html);
    // Pictures pasted as inline data would bloat the database; only library pictures are kept.
    $html = preg_replace('/<img\b[^>]*\ssrc\s*=\s*("|\')\s*data:[^>]*>/i', '', $html);
    // The editor shows pictures as ../uploads/questions/x.png (it lives in /admin); store them relative to the site root.
    $html = preg_replace('~(src\s*=\s*["\'])(?:\.\./)+(uploads/questions/)~i', '$1$2', $html);
    return $html;
}

// Subjects the student registered for that map to real subject rows.
function student_subjects(array $student)
{
    $out = [];
    for ($i = 1; $i <= 9; $i++) {
        $name = trim((string) ($student['subject' . $i] ?? ''));
        if ($name === '' || $name === 'Select Subjects') {
            continue;
        }
        $row = findSubject($student['department'], $name);
        if ($row && !isset($out[$row['sub_id']])) {
            $out[$row['sub_id']] = $row;
        }
    }
    return array_values($out);
}

/* ---------- junior / senior classes ---------- */

// A class is "Junior" (JSS) when its code is JS or its name contains "Junior"; everything else is Senior (SSS).
function class_is_junior($class)
{
    if (!is_array($class)) {
        $class = db_one("SELECT class, sortname FROM class WHERE id = ?", 'i', (int) $class) ?: [];
    }
    return strtoupper(trim((string) ($class['sortname'] ?? ''))) === 'JS'
        || stripos((string) ($class['class'] ?? ''), 'junior') !== false;
}

function levels_for_class($class)
{
    return levels_for_section(class_is_junior($class));
}

// <option> list of all classes, grouped Junior / Senior. $selected = class id to preselect.
function class_options_html($selected = 0)
{
    $groups = ['Junior Secondary (JSS)' => '', 'Senior Secondary (SSS)' => ''];
    foreach (db_all("SELECT id, class, sortname FROM class ORDER BY id") as $c) {
        $g = class_is_junior($c) ? 'Junior Secondary (JSS)' : 'Senior Secondary (SSS)';
        $groups[$g] .= '<option value="' . (int) $c['id'] . '" data-section="' . (class_is_junior($c) ? 'junior' : 'senior') . '"'
            . ((int) $selected === (int) $c['id'] ? ' selected' : '') . '>' . e($c['class']) . '</option>';
    }
    $html = '';
    foreach ($groups as $label => $opts) {
        if ($opts !== '') {
            $html .= '<optgroup label="' . e($label) . '">' . $opts . '</optgroup>';
        }
    }
    return $html;
}

/* ---------- subject opening window ---------- */

// Returns ['open'|'scheduled'|'ended'|'closed', time]. A subject is open when the invigilator has switched it
// to Open with a duration, and the optional "opens at" / "closes at" window includes right now.
function subject_state(array $s)
{
    if (($s['status'] ?? '') !== 'Enabled' || (int) ($s['sub_duration'] ?? 0) <= 0) {
        return ['closed', null];
    }
    $now = time();
    if (!empty($s['open_at']) && strtotime($s['open_at']) !== false && $now < strtotime($s['open_at'])) {
        return ['scheduled', $s['open_at']];
    }
    if (!empty($s['close_at']) && strtotime($s['close_at']) !== false && $now >= strtotime($s['close_at'])) {
        return ['ended', $s['close_at']];
    }
    return ['open', null];
}

/* ---------- question text from CSV: plain text plus a few safe tags ---------- */

// Escapes everything, then re-allows <sup> <sub> <b> <i> <u> <br> and <img> pointing at the image library only.
function question_text_html($text)
{
    $html = e(trim((string) $text));
    $html = preg_replace('~&lt;(/?)(sup|sub|b|i|u)&gt;~i', '<$1$2>', $html);
    $html = preg_replace('~&lt;br\s*/?&gt;~i', '<br>', $html);
    $html = preg_replace_callback(
        '~&lt;img\s+src=(?:&quot;|&\#039;)(uploads/questions/[A-Za-z0-9_.\-]+)(?:&quot;|&\#039;)[^&]*?/?&gt;~i',
        function ($m) { return '<img src="' . $m[1] . '" alt="" style="max-width:100%;">'; },
        $html
    );
    return nl2br($html, false);
}

// Options like "All of the above" or "Both A and B" depend on their position, so those questions are never shuffled.
function options_are_positional(array $opts)
{
    foreach ($opts as $o) {
        if (preg_match('/\b(all|none|neither|both)\b.*\b(above|these|them|of the)\b|\b(above|below)\b|\b(both|and|&|or)\s+[A-D]\b|\b[A-D]\s*(and|&|or)\s*[A-D]\b|\boption\s+[A-D]\b|\bnone of\b|\ball of\b/i', (string) $o)) {
            return true;
        }
    }
    return false;
}

/* ---------- attempt clock (supports invigilator pause / reset / extra time) ---------- */

/** Seconds left. While paused the value is frozen at the moment of the pause. */
function attempt_remaining(array $a, $now = null)
{
    $now = $now ?? time();
    $end = strtotime($a['time']) + ((int) $a['duration'] * 60) + (int) ($a['extra_seconds'] ?? 0);
    $ref = !empty($a['paused_at']) ? strtotime($a['paused_at']) : $now;
    return $end - $ref;
}

function attempt_is_paused(array $a)
{
    return !empty($a['paused_at']);
}
