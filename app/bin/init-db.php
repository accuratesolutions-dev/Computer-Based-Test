<?php
// CLI: creates tables (idempotent) and loads starter data once. Used by the Docker entrypoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
global $con;

function run_sql_file($con, $file)
{
    $sql = file_get_contents($file);
    if (mysqli_multi_query($con, $sql)) {
        do {
            if ($r = mysqli_store_result($con)) { mysqli_free_result($r); }
        } while (mysqli_more_results($con) && mysqli_next_result($con));
    }
}

run_sql_file($con, __DIR__ . '/../db/schema.sql');
echo "Schema ready.\n";

$count = (int) db_one("SELECT COUNT(*) AS c FROM class")['c'];
if ($count === 0) {
    run_sql_file($con, __DIR__ . '/../db/seed.sql');
    echo "Starter data loaded.\n";
    if (env('SEED_DEMO', '0') === '1') {
        run_sql_file($con, __DIR__ . '/../db/demo_questions.sql');
        echo "Demo questions loaded.\n";
    }
}

// After the starter data, so a brand-new database gets Science/Art/Commercial first, then upgrades.
run_migrations();
echo "Upgrades applied (random question sets, Junior class).\n";
