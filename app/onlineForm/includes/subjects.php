<?php
// AJAX (registration form): the levels and subjects that belong to a class, as JSON.
require_once __DIR__ . '/../../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$classId = (int) ($_GET['class'] ?? $_POST['class'] ?? 0);
$class = db_one("SELECT id, class, sortname FROM class WHERE id = ?", 'i', $classId);
if (!$class) {
    echo json_encode(['ok' => false, 'levels' => [], 'subjects' => []]);
    exit;
}
$subjects = [];
foreach (db_all("SELECT sub_name FROM subject WHERE class_id = ? ORDER BY sub_name", 'i', $classId) as $r) {
    $subjects[] = trim($r['sub_name']);
}
echo json_encode([
    'ok'       => true,
    'junior'   => class_is_junior($class),
    'levels'   => levels_for_class($class),
    'subjects' => $subjects,
]);
