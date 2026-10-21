<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/grading.php';

$studentId = (int) $_SESSION['student_id'];
$student = db_one("SELECT * FROM register WHERE id = ?", 'i', $studentId);
$subId = (int) ($_GET['sID'] ?? 0);

function back_to_subjects($msg)
{
    $_SESSION['flash'] = $msg;
    redirect('subjects.php');
}

if (!$student) {
    redirect('includes/logout.php');
}

// Only subjects the student registered for, in their own department.
$subject = null;
foreach (student_subjects($student) as $s) {
    if ((int) $s['sub_id'] === $subId) {
        $subject = $s;
    }
}
if (!$subject) {
    back_to_subjects('That subject is not available to you.');
}
$classId = (int) $subject['class_id'];
if (db_one("SELECT id FROM exam_result WHERE student_id = ? AND subject_id = ?", 'ii', $studentId, $subId)) {
    back_to_subjects('You have already completed this exam.');
}

// Start (or resume) the attempt. The clock lives on the server.
$attempt = db_one(
    "SELECT * FROM dosubject WHERE dostd_id = ? AND doclass_id = ? AND dosub_id = ?",
    'iii', $studentId, $classId, $subId
);
if (!$attempt) {
    // A new start needs the subject to be open right now (a student already writing it may always resume).
    [$state, $when] = subject_state($subject);
    if ($state === 'scheduled') {
        back_to_subjects('This exam opens at ' . date('g:i A, D j M', strtotime($when)) . '. Please wait.');
    } elseif ($state === 'ended') {
        back_to_subjects('The time to start this exam has passed.');
    } elseif ($state !== 'open') {
        back_to_subjects('This exam is not open yet. Please wait for the invigilator.');
    }
    db_exec(
        "INSERT IGNORE INTO dosubject (dostd_id, doclass_id, dosub_id, time, duration, istaken) VALUES (?, ?, ?, ?, ?, 0)",
        'iiisi', $studentId, $classId, $subId, date('Y-m-d H:i:s'), (int) $subject['sub_duration']
    );
    $attempt = db_one(
        "SELECT * FROM dosubject WHERE dostd_id = ? AND doclass_id = ? AND dosub_id = ?",
        'iii', $studentId, $classId, $subId
    );
}

$endsAt = strtotime($attempt['time']) + ((int) $attempt['duration'] * 60);
$remaining = $endsAt - time();
if ($remaining <= 0) {
    finalize_exam($studentId, $subject, [], false); // grades whatever was auto-saved
    back_to_subjects('Your time for this exam had elapsed. Your saved answers were submitted.');
}

$_SESSION['exam_sub_id'] = $subId;
assign_exam_questions($studentId, $subject);               // random set, created once per attempt
$questions = exam_questions($studentId, $subject);          // same set, same order on every refresh
$totalQuestions = count($questions);
$saved = saved_answers($studentId, $subId); // restore progress after a refresh / crash

include __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/fetchUserData.php';
$answeredAtStart = 0;
foreach ($questions as $q) { if (isset($saved[(int) $q['id']])) { $answeredAtStart++; } }
?>
<style>
    .q-card { display: none; }
    .q-card.active { display: block; }
    .q-opt { display: flex; align-items: flex-start; padding: 10px 14px; margin-bottom: 8px; border: 1px solid #dee2e6; border-radius: 6px; cursor: pointer; background: #fff; }
    .q-opt:hover { background: #f1f8f5; border-color: #28a745; }
    .q-opt input { margin: 5px 12px 0 0; }
    .q-opt.chosen { background: #e6f4ea; border-color: #28a745; }
    .q-opt .letter { font-weight: 700; width: 26px; flex: none; }
    .q-nav-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; }
    #navigator { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 18px; }
    #navigator button { width: 40px; height: 38px; padding: 0; border: 1px solid #ced4da; background: #fff; border-radius: 4px; font-weight: 600; }
    #navigator button.answered { background: #28a745; border-color: #28a745; color: #fff; }
    #navigator button.flagged { box-shadow: inset 0 -4px 0 #fd7e14; }
    #navigator button.current { outline: 3px solid #007bff; outline-offset: 1px; }
    .legend span { display: inline-block; margin-right: 14px; font-size: .8rem; color: #6c757d; }
    .legend i { display: inline-block; width: 12px; height: 12px; border-radius: 2px; margin-right: 4px; vertical-align: -1px; border: 1px solid #ced4da; }
    #progressBar { height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; margin: 6px 0 10px; }
    #progressBar div { height: 100%; width: 0; background: #28a745; transition: width .25s; }
</style>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-sm-9">
            <form action="result.php" method="POST" id="examForm">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <div id="loop">
                    <?php if ($totalQuestions === 0): ?>
                        <div class="alert alert-warning mt-3">No questions have been uploaded for this subject yet.
                            <a href="subjects.php">Back to subjects</a></div>
                    <?php endif; ?>
                    <?php foreach ($questions as $n => $q): $qid = (int) $q['id']; ?>
                        <div class="card list-group q-card" data-index="<?php echo $n; ?>">
                            <div class="card-header display-5">
                                SUBJECT: <?php output($subject['sub_name']); ?>
                                <span class="float-right text-muted">Question <?php echo $n + 1; ?> of <?php echo $totalQuestions; ?></span>
                            </div>
                            <div class="card-body">
                                <div class="card-title card-title-bold mb-3"><?php echo safe_html($q['question_desc']); ?></div>
                                <?php
                                $order = array_map('intval', array_filter(explode(',', (string) ($q['option_order'] ?? ''))));
                                if (count($order) !== 4 || count(array_unique($order)) !== 4) { $order = [1, 2, 3, 4]; }
                                foreach (['A', 'B', 'C', 'D'] as $i => $letter): $k = $order[$i]; // letter = position shown; value = original option ?>
                                    <label class="q-opt" for="q<?php echo $qid; ?>_<?php echo $k; ?>">
                                        <input type="radio" name="que_<?php echo $qid; ?>" id="q<?php echo $qid; ?>_<?php echo $k; ?>" class="ans" value="<?php echo $k; ?>"
                                               <?php echo (($saved[$qid] ?? '') === (string) $k) ? 'checked' : ''; ?>>
                                        <span class="letter"><?php echo $letter; ?>.</span>
                                        <span><?php echo e($q['option' . $k]); ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <div class="q-nav-bar">
                                    <button type="button" class="btn btn-outline-secondary js-prev"><i class="fa fa-angle-left"></i> Previous</button>
                                    <button type="button" class="btn btn-outline-warning js-flag" title="Mark to come back to this question"><i class="fa fa-flag"></i> Flag</button>
                                    <button type="button" class="btn btn-primary js-next">Next <i class="fa fa-angle-right"></i></button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalQuestions > 0): ?>
                <div id="progressBar"><div></div></div>
                <div class="legend">
                    <span><i style="background:#28a745;border-color:#28a745"></i>Answered</span>
                    <span><i style="background:#fff"></i>Not answered</span>
                    <span><i style="box-shadow:inset 0 -4px 0 #fd7e14"></i>Flagged</span>
                    <span id="answeredCount" class="font-weight-bold"></span>
                </div>
                <div id="navigator"></div>
                <div class="mt-4 mb-5">
                    <button class="btn btn-success btn-lg" type="button" id="submitExam"><i class="fa fa-check"></i> SUBMIT ANSWERS</button>
                    <span class="text-muted ml-2 small">Tip: use the &larr; &rarr; keys to move, and A&ndash;D (or 1&ndash;4) to pick an answer.</span>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="col-sm-3">
            <div class="mt-3">
                <span style="color: green;">TIME REMAINING: </span>
                <span id="countdown" class="display-5" style="color: green;"></span>
            </div>
            <div id="saveStatus" class="small text-muted" style="margin:4px 0;">Answers are saved automatically.</div>
            <div class="card mt-2">
                <div class="card-body">
                    <div class="card-title card-title-light text-center">Candidate's Bio-Data</div>
                    <img src="<?php echo e(passport_url($image)); ?>" class="card-img-top img-thumbnail"
                         alt="<?php echo e($student['surname'] . ' ' . $student['otherNames']); ?>" style="height:150px;width:80% !important;display:block;margin:0 auto;">
                    <div class="card-title badge-light" style="color: green;">
                        <h6 class="text-center"><?php outputTwo($student['surname'], $student['otherNames']); ?></h6>
                    </div>
                    <p style="color: gray;">
                        Phone No: <span style="color:green;font-weight:bold;font-size:.8rem;"><?php output($phone); ?></span><br>
                        Reg. No: <span style="color:green;font-weight:bold;font-size:.8rem;"><?php output($regno); ?></span><br>
                        Class: <span style="color:green;font-weight:bold;font-size:.8rem;"><?php getDepartment($dept); ?></span><br>
                        Level: <span style="color:green;font-weight:bold;font-size:.8rem;"><?php output($level); ?></span>
                    </p>
                </div>
                <div class="card-footer">
                    <button type="button" id="endExam" class="btn btn-danger btn-block">END EXAM</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
(function () {
    var TOTAL = <?php echo (int) $totalQuestions; ?>;
    var token = <?php echo json_encode(csrf_token()); ?>;
    var storeKey = 'cbt_flags_<?php echo (int) $studentId; ?>_<?php echo (int) $subId; ?>';
    var form = document.getElementById('examForm');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.q-card'));
    var statusEl = document.getElementById('saveStatus');
    var navEl = document.getElementById('navigator');
    var current = 0, finishing = false;
    var pending = {}, inflight = false, retryTimer = null;
    var flags = {};
    try { flags = JSON.parse(localStorage.getItem(storeKey) || '{}') || {}; } catch (e) { flags = {}; }

    /* ---------- state helpers ---------- */
    function radios(i) { return cards[i].querySelectorAll('input.ans'); }
    function qidOf(i) { return radios(i)[0].name.replace('que_', ''); }
    function isAnswered(i) { var r = radios(i); for (var k = 0; k < r.length; k++) { if (r[k].checked) { return true; } } return false; }
    function counts() { var a = 0; for (var i = 0; i < TOTAL; i++) { if (isAnswered(i)) { a++; } } return { answered: a, unanswered: TOTAL - a }; }
    function flaggedList() { var out = []; for (var i = 0; i < TOTAL; i++) { if (flags[qidOf(i)]) { out.push(i + 1); } } return out; }

    function refresh() {
        var c = counts();
        document.querySelector('#progressBar div').style.width = (TOTAL ? c.answered / TOTAL * 100 : 0) + '%';
        document.getElementById('answeredCount').textContent = c.answered + ' of ' + TOTAL + ' answered';
        Array.prototype.forEach.call(navEl.children, function (b, i) {
            b.className = (isAnswered(i) ? 'answered ' : '') + (flags[qidOf(i)] ? 'flagged ' : '') + (i === current ? 'current' : '');
        });
        cards.forEach(function (card) {
            Array.prototype.forEach.call(card.querySelectorAll('.q-opt'), function (o) { o.classList.toggle('chosen', o.querySelector('input').checked); });
        });
        var f = cards[current].querySelector('.js-flag');
        f.classList.toggle('btn-warning', !!flags[qidOf(current)]);
        f.classList.toggle('btn-outline-warning', !flags[qidOf(current)]);
        f.innerHTML = '<i class="fa fa-flag"></i> ' + (flags[qidOf(current)] ? 'Flagged' : 'Flag');
        cards[current].querySelector('.js-prev').disabled = current === 0;
        var nx = cards[current].querySelector('.js-next');
        nx.disabled = current === TOTAL - 1;
    }

    function show(i) {
        if (i < 0 || i >= TOTAL) { return; }
        cards[current].classList.remove('active');
        current = i;
        cards[current].classList.add('active');
        refresh();
        window.scrollTo(0, 0);
    }

    /* ---------- build the question navigator ---------- */
    if (TOTAL > 0) {
        for (var i = 0; i < TOTAL; i++) {
            (function (n) {
                var b = document.createElement('button');
                b.type = 'button'; b.textContent = n + 1; b.title = 'Go to question ' + (n + 1);
                b.addEventListener('click', function () { show(n); });
                navEl.appendChild(b);
            })(i);
        }
        cards.forEach(function (card, n) {
            card.querySelector('.js-prev').addEventListener('click', function () { show(n - 1); });
            card.querySelector('.js-next').addEventListener('click', function () { show(n + 1); });
            card.querySelector('.js-flag').addEventListener('click', function () {
                var id = qidOf(n);
                if (flags[id]) { delete flags[id]; } else { flags[id] = 1; }
                try { localStorage.setItem(storeKey, JSON.stringify(flags)); } catch (e) {}
                refresh();
            });
        });
        // Resume where the student left off: first unanswered question (or the first one).
        var start = 0;
        for (var j = 0; j < TOTAL; j++) { if (!isAnswered(j)) { start = j; break; } }
        cards[start].classList.add('active');
        current = start;
        refresh();
    }

    document.addEventListener('keydown', function (e) {
        if (!TOTAL || e.ctrlKey || e.altKey || e.metaKey || (document.querySelector('.swal-overlay--show-modal'))) { return; }
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'textarea' || tag === 'select' || (tag === 'input' && e.target.type !== 'radio')) { return; }
        if (e.key === 'ArrowRight') { show(current + 1); e.preventDefault(); }
        else if (e.key === 'ArrowLeft') { show(current - 1); e.preventDefault(); }
        else {
            var k = ({ a: 0, b: 1, c: 2, d: 3, 1: 0, 2: 1, 3: 2, 4: 3 })[String(e.key).toLowerCase()];
            if (k !== undefined) { var r = radios(current)[k]; if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); } }
        }
    });

    /* ---------- timer: the server decides, this only displays it ---------- */
    var endsAt = Date.now() + <?php echo (int) $remaining; ?> * 1000;
    var el = document.getElementById('countdown');
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function submitNow() {
        if (finishing) { return; }
        finishing = true; pending = {};
        form.submit();
    }
    function tick() {
        var left = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
        el.textContent = pad(Math.floor(left / 3600)) + ':' + pad(Math.floor(left % 3600 / 60)) + ':' + pad(left % 60);
        if (left <= 120) { el.style.color = 'red'; }
        if (left === 0) { clearInterval(timer); setStatus('Time is up - submitting...', 'red'); setTimeout(submitNow, 400); }
    }
    var timer = setInterval(tick, 1000);
    tick();

    /* ---------- auto-save ---------- */
    function setStatus(text, color) { statusEl.textContent = text; statusEl.style.color = color || ''; }
    function hasPending() { return Object.keys(pending).length > 0; }
    function flush() {
        if (inflight || !hasPending() || finishing) { return; }
        var qid = Object.keys(pending)[0], choice = pending[qid];
        inflight = true;
        setStatus('Saving...', '');
        var body = new URLSearchParams({ question_id: qid, choice: choice, csrf: token });
        fetch('save_answer.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); })
            .then(function (res) {
                inflight = false;
                if (res.json && res.json.ok) {
                    if (pending[qid] === choice) { delete pending[qid]; }
                    setStatus(hasPending() ? 'Saving...' : 'All answers saved ✓', 'green');
                    flush();
                } else if (res.status === 409) {
                    delete pending[qid];
                    setStatus('Time is up - submitting...', 'red');
                } else {
                    throw new Error((res.json && res.json.msg) || 'save failed');
                }
            })
            .catch(function () {
                inflight = false;
                setStatus('Connection problem - retrying... (your answers are kept on this page)', 'red');
                clearTimeout(retryTimer);
                retryTimer = setTimeout(flush, 3000);
            });
    }
    Array.prototype.forEach.call(document.querySelectorAll('input.ans[type=radio]'), function (r) {
        r.addEventListener('change', function () {
            pending[r.name.replace('que_', '')] = r.value;
            refresh();
            flush();
        });
    });
    window.addEventListener('beforeunload', function (e) {
        if (!finishing && hasPending()) { e.preventDefault(); e.returnValue = ''; }
    });

    /* ---------- submit (with a clear summary) ---------- */
    function confirmSubmit() {
        var c = counts(), fl = flaggedList();
        var lines = [];
        lines.push(c.unanswered > 0 ? 'You have ' + c.unanswered + ' unanswered question' + (c.unanswered > 1 ? 's' : '') + '.' : 'You have answered every question.');
        if (fl.length) { lines.push('Flagged: ' + fl.join(', ') + '.'); }
        lines.push('Your answers will be scored immediately and you cannot retake this subject.');
        swal({ title: 'Submit your answers?', text: lines.join('\n'), icon: 'warning', buttons: ['Keep working', 'Submit now'], dangerMode: true })
            .then(function (yes) { if (yes) { submitNow(); } });
    }
    var sb = document.getElementById('submitExam');
    if (sb) { sb.addEventListener('click', confirmSubmit); }
    document.getElementById('endExam').addEventListener('click', TOTAL > 0 ? confirmSubmit : function () { window.location = 'subjects.php'; });
    form.addEventListener('submit', function (e) { if (!finishing) { e.preventDefault(); confirmSubmit(); } });
})();
</script>
