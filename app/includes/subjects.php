<?php
// AJAX: returns <option> list of subjects for a class (admin forms).
require_once __DIR__ . '/admin_session.php';

$classId = (int) ($_POST['classSubject'] ?? $_POST['subjectStatus'] ?? 0);
echo '<option selected="selected" value="">Select Subjects</option>';
foreach (db_all("SELECT sub_id, sub_name FROM subject WHERE class_id = ? ORDER BY sub_name", 'i', $classId) as $row) {
    echo '<option value="' . (int) $row['sub_id'] . '">' . e($row['sub_name']) . '</option>';
}
