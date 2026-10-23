<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin_role();

$classId = (int) ($_GET['class'] ?? 0);
$level = trim((string) ($_GET['level'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$since = (int) ($_GET['since'] ?? 0); // students with id > since (the batch just bulk-registered)
$like = '%' . addcslashes($q, '%_\\') . '%';

$cards = [];
if (isset($_GET['show'])) {
    $cards = db_all(
        "SELECT r.id, r.examNumber, r.surname, r.otherNames, r.DateOfBirth, r.level, r.passport, r.department, c.class,
                r.subject1, r.subject2, r.subject3, r.subject4, r.subject5, r.subject6, r.subject7, r.subject8, r.subject9
           FROM register r LEFT JOIN class c ON c.id = r.department
          WHERE (? = 0 OR r.department = ?) AND (? = '' OR r.level = ?) AND r.id > ?
            AND (r.examNumber LIKE ? OR r.surname LIKE ? OR r.otherNames LIKE ?)
          ORDER BY r.surname, r.otherNames LIMIT 1000",
        'iississs', $classId, $classId, $level, $level, $since, $like, $like, $like
    );
}

if ($cards) {
    // Printable page on its own (no admin chrome).
    $school = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Akyerit CBT';
    ?><!doctype html><html><head><meta charset="utf-8"><title>Exam cards</title>
    <style>
    body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:10px;color:#111}
    .bar{margin-bottom:10px}.bar button{padding:6px 14px;font-size:14px}
    .grid{display:flex;flex-wrap:wrap;gap:8px}
    .card{width:330px;border:1.5px solid #333;border-radius:6px;padding:8px 10px;box-sizing:border-box;page-break-inside:avoid;break-inside:avoid;display:flex;gap:8px;min-height:150px}
    .card img{width:70px;height:85px;object-fit:cover;border:1px solid #999}
    .card h3{margin:0 0 4px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #333;padding-bottom:3px}
    .card .n{font-size:15px;font-weight:bold}.card .no{font-size:18px;font-weight:bold;letter-spacing:1px;margin:3px 0}
    .card small{color:#444;display:block;font-size:10.5px;line-height:1.35}
    @media print{.bar{display:none}body{padding:0}}
    </style></head><body>
    <div class="bar"><button onclick="window.print()">Print (<?php echo count($cards); ?> cards)</button> <a href="exam_cards.php">Back</a></div>
    <div class="grid">
    <?php foreach ($cards as $c):
        $subs = [];
        for ($i = 1; $i <= 9; $i++) { $n = trim((string) $c['subject' . $i]); if ($n !== '' && $n !== 'Select Subjects') { $subs[] = $n; } }
        ?>
        <div class="card">
            <?php if ($c['passport'] !== ''): ?><img src="<?php echo e(passport_url($c['passport'])); ?>" alt=""><?php endif; ?>
            <div>
                <h3><?php echo e($school); ?> - Exam Card</h3>
                <div class="n"><?php echo e($c['surname'] . ' ' . $c['otherNames']); ?></div>
                <div class="no"><?php echo e($c['examNumber']); ?></div>
                <small><?php echo e($c['class'] . ' ' . $c['level']); ?> &middot; Password: date of birth <strong><?php echo e($c['DateOfBirth']); ?></strong></small>
                <small><?php echo e(implode(', ', $subs)); ?></small>
            </div>
        </div>
    <?php endforeach; ?>
    </div></body></html><?php
    exit;
}

admin_head('Exam Cards', 'cards');
if (isset($_GET['show'])) { echo '<div class="alert alert-warning">No students matched.</div>'; }
?>
<p class="text-muted">Print an exam card for each student showing the exam number and what to type to log in. Choose a class (and level), or search for one student.</p>
<form class="form-inline" method="get" target="_blank" style="margin-bottom:15px;">
    <input type="hidden" name="show" value="1">
    <select name="class" class="form-control"><option value="0">All classes</option><?php echo class_options_html($classId); ?></select>
    <input type="text" name="level" class="form-control" placeholder="Level (e.g. S.S.S 1)" value="<?php echo e($level); ?>">
    <input type="text" name="q" class="form-control" placeholder="Name or exam number" value="<?php echo e($q); ?>">
    <?php if ($since): ?><input type="hidden" name="since" value="<?php echo $since; ?>"><?php endif; ?>
    <button class="btn btn-primary" type="submit"><i class="fa fa-print"></i> Show cards to print</button>
</form>
<?php admin_foot();
