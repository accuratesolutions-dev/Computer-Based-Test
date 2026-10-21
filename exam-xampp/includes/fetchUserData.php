<?php
// Loads the logged-in student's record into variables used by the exam page.
$fetchUserId = db_one("SELECT * FROM register WHERE id = ?", 'i', (int) $_SESSION['student_id']);
$phone = $fetchUserId['phoneNumber'] ?? '';
$regno = $fetchUserId['examNumber'] ?? '';
$dept  = $fetchUserId['department'] ?? '';
$level = $fetchUserId['level'] ?? '';
$image = $fetchUserId['passport'] ?? '';
