<?php
require_once __DIR__ . '/includes/session.php';

$studentId = (int) $_SESSION['student_id'];
$student = db_one("SELECT * FROM register WHERE id = ?", 'i', $studentId);
if (!$student) {
    redirect('includes/logout.php');
}
$_SESSION['seen_instructions'] = true;
$subjects = student_subjects($student);

$done = [];      // subject_id => result row
foreach (db_all("SELECT subject_id, correct, total, percent FROM exam_result WHERE student_id = ?", 'i', $studentId) as $r) {
    $done[(int) $r['subject_id']] = $r;
}
$started = [];   // subject_id => seconds left, for attempts that are in progress
foreach (db_all("SELECT dosub_id, time, duration FROM dosubject WHERE dostd_id = ? AND istaken = 0", 'i', $studentId) as $r) {
    $started[(int) $r['dosub_id']] = strtotime($r['time']) + ((int) $r['duration'] * 60) - time();
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$questionCount = [];
foreach (db_all("SELECT subject_id, COUNT(*) AS c FROM question GROUP BY subject_id") as $r) {
    $questionCount[(int) $r['subject_id']] = (int) $r['c'];
}

include __DIR__ . '/includes/header.php';
?>
<body class="index_body">
<?php include __DIR__ . '/includes/student_bar.php'; ?>
<div class="container">
    <div class="row">
        <div class="col-lg-12 col-sm-12 take-in">
            <div class="custom mt-4 show">
                <h1 class="text-center mb-2" id="list-subject">LIST OF SUBJECTS</h1>
                <p class="text-center text-muted" id="refreshNote">New subjects appear here by themselves when the invigilator opens them.</p>
                <?php if ($flash): ?>
                    <div class="alert alert-warning"><?php echo e($flash); ?></div>
                <?php endif; ?>
                <table class="table rounded-circle" id="subjectsTable">
                    <thead class="thead-light rounded">
                        <tr><th>#</th><th>SUBJECT</th><th>DURATION</th><th>ACTION</th></tr>
                    </thead>
                    <tbody id="subjectsBody">
                    <?php if (!$subjects): ?>
                        <tr><td colspan="4" class="text-center">No subjects found for your registration.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($subjects as $i => $s):
                        $id = (int) $s['sub_id'];
                        $mins = (int) $s['sub_duration'];
                        [$state, $when] = subject_state($s);
                        $open = $state === 'open'; ?>
                        <tr>
                            <th scope="row"><?php echo $i + 1; ?></th>
                            <td><?php echo e(trim($s['sub_name'])); ?></td>
                            <td><?php echo $mins > 0 && $state !== 'closed' ? $mins . ' mins' : '&mdash;'; ?></td>
                            <td>
                                <?php if (isset($done[$id])): ?>
                                    <span class="badge badge-secondary p-2">COMPLETED</span>
                                    <strong class="ml-2"><?php echo (int) $done[$id]['correct']; ?> / <?php echo (int) $done[$id]['total']; ?></strong>
                                    <small class="text-muted">(<?php echo e($done[$id]['percent']); ?>%)</small>
                                <?php elseif (isset($started[$id])): ?>
                                    <a href="take_exam.php?sID=<?php echo $id; ?>" class="btn btn-warning">RESUME</a>
                                    <small class="text-muted ml-2"><?php echo $started[$id] > 0 ? floor($started[$id] / 60) . ' min left' : 'time is up'; ?></small>
                                <?php elseif ($open): ?>
                                    <a href="take_exam.php?sID=<?php echo $id; ?>" class="btn btn-success js-start"
                                       data-name="<?php echo e(trim($s['sub_name'])); ?>" data-mins="<?php echo $mins; ?>"
                                       data-questions="<?php echo (int) ($s['question_limit'] > 0 ? min((int) $s['question_limit'], $questionCount[$id] ?? 0) : ($questionCount[$id] ?? 0)); ?>">START</a>
                                <?php elseif ($state === 'scheduled'): ?>
                                    <span class="badge badge-info p-2">OPENS <?php echo e(date('g:i A', strtotime($when))); ?></span>
                                    <small class="text-muted"><?php echo e(date('D j M', strtotime($when))); ?></small>
                                <?php elseif ($state === 'ended'): ?>
                                    <span class="badge badge-secondary p-2">CLOSED</span>
                                <?php else: ?>
                                    <span class="badge badge-warning p-2">NOT OPEN</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
<script src="includes/quitExam.js"></script>
<script>
// Starting a subject starts the clock, so ask once. Works for rows that are re-drawn by the auto-refresh too.
document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a.js-start') : null;
    if (!a) { return; }
    e.preventDefault();
    var n = a.getAttribute('data-questions');
    swal({
        title: 'Start ' + a.getAttribute('data-name') + '?',
        text: 'You will have ' + a.getAttribute('data-mins') + ' minutes' + (n > 0 ? ' for ' + n + ' questions' : '') + '.\nThe timer starts now and cannot be paused. Your answers are saved as you go.',
        icon: 'info',
        buttons: ['Not yet', 'Start exam']
    }).then(function (yes) { if (yes) { window.location = a.href; } });
});

// Auto-refresh the list (every 8 s while this tab is visible) so a subject opened by the invigilator just appears.
(function () {
    var busy = false;
    function refresh() {
        if (busy || document.hidden || document.querySelector('.swal-overlay--show-modal')) { return; }
        busy = true;
        fetch('subjects.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById('subjectsBody');
                if (!fresh) { window.location.reload(); return; }   // session ended: show the login page
                var cur = document.getElementById('subjectsBody');
                if (cur.innerHTML.replace(/\s+/g, ' ') !== fresh.innerHTML.replace(/\s+/g, ' ')) { cur.innerHTML = fresh.innerHTML; }
            })
            .catch(function () {})
            .then(function () { busy = false; });
    }
    setInterval(refresh, 8000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
})();
</script>
