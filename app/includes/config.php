<?php
/**
 * Central configuration. All settings come from environment variables so the
 * same code runs locally (docker compose) and in production without edits.
 */
if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}

// Optional per-install settings file written by the Windows installer (returns an array of KEY => value).
// Real environment variables always win. Never served: includes/ is blocked by .htaccess and the file prints nothing.
if (is_file(__DIR__ . '/local-config.php')) {
    $__local = include __DIR__ . '/local-config.php';
    if (is_array($__local)) {
        foreach ($__local as $__k => $__v) {
            if (getenv($__k) === false) { putenv($__k . '=' . $__v); }
        }
    }
    unset($__local, $__k, $__v);
}

date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Lagos'));

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', (int) env('DB_PORT', 3306));
define('DB_NAME', env('DB_NAME', 'tri-exam'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
// Sub-folder the app is served from ("" when served from the domain root).
// Set APP_BASE_PATH to override; otherwise it is detected from where the app sits under the web root
// (e.g. C:/xampp/htdocs/exam -> "/exam").
$__base = env('APP_BASE_PATH');
if ($__base === null) {
    $__base = '';
    $__root = realpath(dirname(__DIR__));
    $__doc  = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($__root && $__doc) {
        $__root = str_replace('\\', '/', $__root);
        $__doc  = rtrim(str_replace('\\', '/', $__doc), '/');
        if ($__doc !== '' && strncasecmp($__root, $__doc, strlen($__doc)) === 0) {
            $__base = substr($__root, strlen($__doc));
        }
    }
}
define('APP_BASE_PATH', rtrim((string) $__base, '/'));
unset($__base, $__root, $__doc);
define('APP_DEBUG', env('APP_DEBUG', '0') === '1');

// Throw exceptions on DB errors instead of leaking SQL / dying mid-page.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!APP_DEBUG) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

set_exception_handler(function ($e) {
    error_log('Unhandled exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo APP_DEBUG
        ? '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>'
        : 'Something went wrong. Please try again or contact the administrator.';
    exit;
});

global $con;
$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
mysqli_set_charset($con, 'utf8mb4');
