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
        "SELECT d.dostd_id, d.dosub_id, d.time, d.duration, r.examNumber, r.surname, r.otherNames, r.level, c.class, s.sub_name,
                (SELECT COUNT(*) FROM exam_question e WHERE e.student_id = d.dostd_id AND e.subject_id = d.dosub_id) AS total,
                (SELECT COUNT(*) FROM exam_answer a WHERE a.student_id = d.dostd_id AND a.subject_id = d.dosub_id
                    AND (NOT EXISTS (SELECT 1 FROM exam_question e2 WHERE e2.student_id = d.dostd_id AND e2.subject_id = d.dosub_id)
                         OR a.question_id IN (SELECT e3.question_id FROM exam_question e3 WHERE e3.student_id = d.dostd_id AND e3.subject_id = d.dosub_id))) AS answered,
                (SELECT MAX(a2.saved_at) FROM exam_answer a2 WHERE a2.student_id = d.dostd_id AND a2.subject_id = d.dosub_id) AS last_saved
           FROM dosubject d
           JOIN register r ON r.id = d.dostd_id
           JOIN subject s ON s.sub_id = d.dosub_id
           JOIN class c ON c.id = d.doclass_id
          WHERE d.istaken = 0
          ORDER BY d.time"
    ) as $r) {
        $left = strtotime($r['time']) + ((int) $r['duration'] * 60) - $now;
        $rows[] = [
            'exam' => $r['examNumber'], 'name' => trim($r['surname'] . ' ' . $r['otherNames']),
            'class' => $r['class'] . ' ' . $r['level'], 'subject' => trim($r['sub_name']),
            'left' => max(0, $left), 'answered' => (int) $r['answered'], 'total' => (int) $r['total'],
            'idle' => $r['last_saved'] ? max(0, $now - strtotime($r['last_saved'])) : null,
            'started' => date('H:i', strtotime($r['time'])),
        ];
    }
    $open = [];
    foreach (db_all("SELECT s.sub_id, s.sub_name, s.status, s.sub_duration, s.open_at, s.close_at, c.class FROM subject s JOIN class c ON c.id = s.class_id WHERE s.status = 'Enabled'") as $s) {
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
?>
<div class="row" style="margin-bottom:10px;">
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body"><div class="stat" id="nWriting">-</div>writing now</div></div></div>
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body"><div class="stat" id="nToday">-</div>submitted today</div></div></div>
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body"><div class="stat" id="nFinished">-</div>results in total</div></div></div>
    <div class="col-sm-3"><div class="panel panel-default"><div class="panel-body"><div class="stat" id="nReg">-</div>registered students</div></div></div>
</div>
<p class="text-muted small">Refreshes by itself every 5 seconds. <span id="stamp"></span>
   <span id="openNow"></span></p>
<div class="panel panel-default"><div class="table-responsive">
<table class="table table-striped table-tight" style="margin:0;">
    <thead><tr><th>Exam No.</th><th>Name</th><th>Class</th><th>Subject</th><th>Started</th><th>Time left</th><th style="width:220px;">Answered</th><th>Last saved</th></tr></thead>
    <tbody id="rows"><tr><td colspan="8" class="text-center text-muted">Loading...</td></tr></tbody>
</table></div></div>
<script>
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
                    html += '<tr><td>' + esc(r.exam) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r['class']) + '</td><td>' + esc(r.subject) + '</td><td>' + esc(r.started) + '</td>'
                        + '<td><strong' + (r.left <= 300 ? ' class="text-danger"' : '') + '>' + fmt(r.left) + '</strong></td>'
                        + '<td><div class="progress" style="margin:0;height:18px;"><div class="progress-bar progress-bar-success" style="width:' + pct + '%;min-width:2em;">' + r.answered + '/' + r.total + '</div></div></td>'
                        + '<td>' + idle + '</td></tr>';
                });
                document.getElementById('rows').innerHTML = html || '<tr><td colspan="8" class="text-center text-muted">Nobody is writing an exam right now.</td></tr>';
            })
            .catch(function () { document.getElementById('stamp').textContent = 'Could not refresh - retrying...'; });
    }
    load();
    setInterval(function () { if (!document.hidden) { load(); } }, 5000);
})();
</script>
<?php admin_foot();
