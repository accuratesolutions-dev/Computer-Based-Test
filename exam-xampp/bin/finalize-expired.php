<?php
// CLI (optional cron): grade attempts whose time ran out without a submission.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/grading.php';
echo "Finalized " . finalize_expired_attempts() . " expired attempt(s).\n";
