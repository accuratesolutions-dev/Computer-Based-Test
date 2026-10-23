<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/grading.php';

// JSON feed for the auto-refresh (also returned as the first page render).
function monitor_data()
{
    finalize_expired_attempts(); // anyone whose time ran out is graded, so counts stay honest
    $now = time();
    $rows = [];
    foreach (db_all(
        "SELECT d.dostd_id, d.dosub_id, d.time, d.duration, d.paused_at, d.extra_seconds, r.examNumber, r.surname, r.otherNames, r.level, c.class, s.sub_name,
                (SELECT COUNT(*) FROM exam_question e WHERE e.student_id = d.dostd_id AND e.subject_id = d.dosub_id) AS total,
                (SELECT COUNT(*) FROM exam_answer a WHERE a.student_id = d.dostd_id AND a.subject_id = d.dosub_id
                    AND (NOT EXISTS (SELECT 1 FROM exam_question e2 WHERE e2.student_id = d.dostd_id AND e2.subject_id = d.dosub_id)
                         OR a.question_id IN (SELECT e3.question_id FROM exam_question e3 WHERE e3.student_id = d.dostd_id AND e3.subject_id = d.dosub_id))) AS answered,
                (SELECT MAX(a2.saved_at) FROM exam_answer a2 WHERE a2.student_id = d.dostd_id AND a2.subject_id = d.dosub_id) AS last_saved
           FROM dosubject d
           JOIN register r ON r.id = d.dostd_id
           JOIN subject s ON s.sub_id = d.dosub_id
           JOIN class c ON c.id = d.doclass_id
          WHERE d.istaken = 0 AND " . subject_scope_sql('d.dosub_id') . "
          ORDER BY d.time"
    ) as $r) {
        $left = attempt_remaining($r, $now);
        $rows[] = [
            'exam' => $r['examNumber'], 'name' => trim($r['surname'] . ' ' . $r['otherNames']),
            'class' => $r['class'] . ' ' . $r['level'], 'subject' => trim($r['sub_name']),
            'left' => max(0, $left), 'paused' => attempt_is_paused($r), 'sid' => (int) $r['dostd_id'], 'sub' => (int) $r['dosub_id'], 'answered' => (int) $r['answered'], 'total' => (int) $r['total'],
            'idle' => $r['last_saved'] ? max(0, $now - strtotime($r['last_saved'])) : null,
            'started' => date('H:i', strtotime($r['time'])),
        ];
    }
    $open = [];
    foreach (db_all("SELECT s.sub_id, s.sub_name, s.status, s.sub_duration, s.open_at, s.close_at, c.class FROM subject s JOIN class c ON c.id = s.class_id WHERE s.status = 'Enabled' AND " . subject_scope_sql('s.sub_id')) as $s) {
        [$state] = subject_state($s);
        $open[] = ['class' => $s['class'], 'subject' => trim($s['sub_name']), 'state' => $state, 'minutes' => (int) $s['sub_duration']];
    }
    return [
        'time' => date('H:i:s'),
        'registered' => (int) db_one("SELECT COUNT(*) AS c FROM register")['c'],
        'finished' => (int) db_one("SELECT COUNT(*) AS c FROM exam_result")['c'],
        'today' => (int) db_one("SELECT COUNT(*) AS c FROM exam_result WHERE submitted_at >= ?", 's', date('Y-m-d 00:00:00'))['c'],
        'writing' => $rows,
        'open' => $open,
    ];
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(monitor_data());
    exit;
}

admin_head('Live monitor', 'monitor');
$canControl = is_admin_role();
?>
<div class="row" style="margin-bottom:10px;">
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body"><div class="stat" id="nWriting">-</div>writing now</div></div></div>
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body"><div class="stat" id="nToday">-</div>submitted today</div></div></div>
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body"><div class="stat" id="nFinished">-</div>results in total</div></div></div>
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body"><div class="stat" id="nReg">-</div>registered students</div></div></div>
</div>
<?php if ($canControl): ?>
<div style="margin-bottom:10px;">
    <button class="btn btn-warning btn-sm js-all" data-action="pauseAll"><i class="fa fa-pause"></i> Pause everyone</button>
    <button class="btn btn-success btn-sm js-all" data-action="resumeAll"><i class="fa fa-play"></i> Resume everyone</button>
    <span class="small text-muted" style="margin-left:8px;">Pausing stops the clock and covers the student's screen; resuming gives the paused time back.</span>
</div>
<?php endif; ?>
<p class="text-muted small">Refreshes by itself every 5 seconds. <span id="stamp"></span>
   <span id="openNow"></span></p>
<div class="panel panel-default"><div class="table-responsive">
<table class="table table-striped table-tight" style="margin:0;">
    <thead><tr><th>Exam No.</th><th>Name</th><th>Class</th><th>Subject</th><th>Started</th><th>Time left</th><th style="width:220px;">Answered</th><th>Last saved</th><?php if ($canControl): ?><th style="width:210px;">Time controls</th><?php endif; ?></tr></thead>
    <tbody id="rows"><tr><td colspan="9" class="text-center text-muted">Loading...</td></tr></tbody>
</table></div></div>
<script>
var CAN = <?php echo $canControl ? 'true' : 'false'; ?>, TOKEN = <?php echo json_encode(csrf_token()); ?>;
(function () {
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function fmt(sec) { var m = Math.floor(sec / 60), s = sec % 60; return m + ':' + (s < 10 ? '0' : '') + s; }
    function load() {
        fetch('monitor.php?json=1', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
            .then(function (d) {
                document.getElementById('nWriting').textContent = d.writing.length;
                document.getElementById('nToday').textContent = d.today;
                document.getElementById('nFinished').textContent = d.finished;
                document.getElementById('nReg').textContent = d.registered;
                document.getElementById('stamp').textContent = 'Last updated ' + d.time + '.';
                document.getElementById('openNow').textContent = d.open.length
                    ? ' Open: ' + d.open.map(function (o) { return o.class + ' ' + o.subject + (o.state === 'scheduled' ? ' (scheduled)' : o.state === 'ended' ? ' (closed)' : ''); }).join(', ') + '.'
                    : ' No subject is open.';
                var html = '';
                d.writing.forEach(function (r) {
                    var pct = r.total ? Math.round(r.answered * 100 / r.total) : 0;
                    var idle = r.idle == null ? '<span class="text-muted">no answers yet</span>'
                        : (r.idle > 300 ? '<span class="text-danger">' + Math.floor(r.idle / 60) + ' min ago</span>' : (r.idle < 60 ? 'just now' : Math.floor(r.idle / 60) + ' min ago'));
                    var ctl = '';
                    if (CAN) {
                        var b = 'data-s="' + r.sid + '" data-sub="' + r.sub + '"';
                        ctl = '<td>' + (r.paused
                            ? '<button class="btn btn-xs btn-success js-act" data-action="resume" ' + b + ' title="Resume"><i class="fa fa-play"></i></button> '
                            : '<button class="btn btn-xs btn-warning js-act" data-action="pause" ' + b + ' title="Pause"><i class="fa fa-pause"></i></button> ')
                            + '<button class="btn btn-xs btn-default js-act" data-action="add" ' + b + ' title="Add minutes"><i class="fa fa-plus"></i> min</button> '
                            + '<button class="btn btn-xs btn-danger js-act" data-action="reset" ' + b + ' title="Give the full time again"><i class="fa fa-undo"></i> reset time</button></td>';
                    }
                    html += '<tr' + (r.paused ? ' class="warning"' : '') + '><td>' + esc(r.exam) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r['class']) + '</td><td>' + esc(r.subject) + '</td><td>' + esc(r.started) + '</td>'
                        + '<td>' + (r.paused ? '<span class="label label-warning">PAUSED</span> ' : '') + '<strong' + (r.left <= 300 && !r.paused ? ' class="text-danger"' : '') + '>' + fmt(r.left) + '</strong></td>'
                        + '<td><div class="progress" style="margin:0;height:18px;"><div class="progress-bar progress-bar-success" style="width:' + pct + '%;min-width:2em;">' + r.answered + '/' + r.total + '</div></div></td>'
                        + '<td>' + idle + '</td>' + ctl + '</tr>';
                });
                document.getElementById('rows').innerHTML = html || '<tr><td colspan="9" class="text-center text-muted">Nobody is writing an exam right now.</td></tr>';
            })
            .catch(function () { document.getElementById('stamp').textContent = 'Could not refresh - retrying...'; });
    }
    function send(params, done) {
        params.csrf = TOKEN;
        fetch('attempt_action.php', { method: 'POST', body: new URLSearchParams(params), credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { alert(j.msg || (j.ok ? 'Done.' : 'Failed.')); load(); })
            .catch(function () { alert('Could not reach the server.'); });
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.js-act, .js-all'); if (!b) { return; }
        var a = b.getAttribute('data-action');
        if (b.classList.contains('js-all')) {
            if (confirm(a === 'pauseAll' ? 'Pause every student who is writing?' : 'Resume every paused student?')) { send({ action: a, subject: 0 }); }
            return;
        }
        var p = { action: a, student: b.getAttribute('data-s'), subject: b.getAttribute('data-sub') };
        if (a === 'add') { var m = prompt('Add how many minutes? (1-120)', '5'); if (!m) { return; } p.minutes = m; }
        if (a === 'reset' && !confirm('Give this student the full time again? Their saved answers are kept.')) { return; }
        send(p);
    });
    load();
    setInterval(function () { if (!document.hidden) { load(); } }, 5000);
})();
</script>
<?php admin_foot();
