<?php
/**
 * Small key/value settings stored in the app_meta table, plus the grading scale and registration lock built on them.
 */

function setting($key, $default = null)
{
    static $cache = null;
    if ($cache === null || $key === '__reset__') {
        $cache = [];
        foreach (db_all("SELECT k, v FROM app_meta") as $r) {
            $cache[$r['k']] = $r['v'];
        }
        if ($key === '__reset__') {
            return null;
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function setting_set($key, $value)
{
    db_exec(
        "INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
        'ss', (string) $key, (string) $value
    );
    setting('__reset__');
}

/* ---------- pass mark & grading ---------- */

function default_grade_scale()
{
    return [[70, 'A', 'Excellent'], [60, 'B', 'Very Good'], [50, 'C', 'Good'], [45, 'D', 'Fair'], [40, 'E', 'Pass'], [0, 'F', 'Fail']];
}

// Stored compactly as "70:A:Excellent|60:B:Very Good|..." (fits the 255-character value column).
function grade_scale_parse($text)
{
    $bands = [];
    foreach (explode('|', (string) $text) as $part) {
        $bits = explode(':', $part, 3);
        if (count($bits) === 3 && is_numeric($bits[0])) {
            $bands[] = [(float) $bits[0], trim($bits[1]), trim($bits[2])];
        }
    }
    if (!$bands) {
        return default_grade_scale();
    }
    usort($bands, function ($a, $b) { return $b[0] <=> $a[0]; });
    return $bands;
}

function grade_scale_format(array $bands)
{
    $out = [];
    foreach ($bands as $b) {
        $out[] = rtrim(rtrim(number_format((float) $b[0], 1, '.', ''), '0'), '.') . ':' . $b[1] . ':' . $b[2];
    }
    return implode('|', $out);
}

function grade_scale()
{
    return grade_scale_parse(setting('grade_scale', ''));
}

function pass_mark()
{
    $v = setting('pass_mark', '50');
    return is_numeric($v) ? max(0, min(100, (float) $v)) : 50.0;
}

// ['grade' => 'B', 'remark' => 'Very Good', 'pass' => true]
function grade_for($percent)
{
    $percent = (float) $percent;
    foreach (grade_scale() as $b) {
        if ($percent >= $b[0]) {
            return ['grade' => $b[1], 'remark' => $b[2], 'pass' => $percent >= pass_mark()];
        }
    }
    return ['grade' => '-', 'remark' => '', 'pass' => $percent >= pass_mark()];
}

/* ---------- registration lock ---------- */

function registration_open()
{
    if (setting('registration_open', '1') === '0') {
        return false;
    }
    $closes = (string) setting('registration_closes', '');
    if ($closes !== '' && strtotime($closes) !== false && time() >= strtotime($closes)) {
        return false;
    }
    return true;
}
