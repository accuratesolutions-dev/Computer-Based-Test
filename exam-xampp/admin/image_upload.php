<?php
// AJAX endpoint for the "Add picture" button in the question editor. Returns JSON.
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/question_images.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

function out($ok, $data = [], $code = 200) { http_response_code($code); echo json_encode(['ok' => $ok] + $data); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { out(false, ['error' => 'POST only'], 405); }
if (!csrf_check()) { out(false, ['error' => 'Your session expired. Reload the page and try again.'], 403); }
$f = $_FILES['pic'] ?? null;
if (!$f) { out(false, ['error' => 'No picture received (it may be larger than the server limit of ' . ini_get('post_max_size') . ').'], 400); }
[$name, $err] = qimg_store($f['tmp_name'], $f['name'], (int) $f['size'], (int) $f['error']);
if ($err !== '') { out(false, ['error' => $f['name'] . ': ' . $err], 422); }
out(true, ['name' => $name, 'path' => 'uploads/questions/' . $name]);
