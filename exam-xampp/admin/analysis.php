<?php
require_once __DIR__ . '/../includes/admin_layout.php';

$subjectId = (int) ($_GET['subject'] ?? 0);
$subject = ($subjectId && subject_allowed($subjectId)) ? db_one("SELECT s.sub_id, s.sub_name, c.class FROM subject s JOIN class c ON c.id = s.class_id WHERE s.sub_id = ?", 'i', $subjectId) : null;

$items = [];
if ($subject) {
    $qs = db_all("SELECT id, question_desc, option1, option2, option3, option4, true_answer, topic FROM question WHERE subject_id = ? ORDER BY id", 'i', $subjectId);
    $dist = [];
    foreach (db_all("SELECT question_id, choice, COUNT(*) AS n FROM exam_answer WHERE subject_id = ? GROUP BY question_id, choice", 'i', $subjectId) as $d) {
        $dist[(int) $d['question_id']][(int) $d['choice']] = (int) $d['n'];
    }
    foreach ($qs as $q) {
        $key = 0;
        for ($k = 1; $k <= 4; $k++) {
            if (strcasecmp(trim($q['option' . $k]), trim($q['true_answer'])) === 0) { $key = $k; break; }
        }
        $c = $dist[(int) $q['id']] ?? [];
        $n = array_sum($c);
        $top = 0; $topN = 0;
        foreach ($c as $opt => $cnt) { if ($cnt > $topN) { $topN = $cnt; $top = $opt; } }
        $pct = ($n && $key) ? round(($c[$key] ?? 0) * 100 / $n) : null;
        $flag = '';
        if ($n >= 5 && $key && $top && $top !== $key && $topN > 0.5 * $n) { $flag = 'Most students chose ' . chr(64 + $top) . ' but the key is ' . chr(64 + $key) . ' - check the answer key.'; }
        elseif ($n >= 5 && $pct !== null && $pct < 20) { $flag = 'Very few got this right - check the key or the wording.'; }
        elseif ($n >= 5 && $pct !== null && $pct > 95) { $flag = 'Almost everyone got this right - too easy?'; }
        $items[] = ['q' => $q, 'key' => $key, 'c' => $c, 'n' => $n, 'pct' => $pct, 'flag' => $flag];
    }
    if (isset($_GET['sort']) && $_GET['sort'] === 'hard') {
        usort($items, function ($a, $b) { return ($a['pct'] ?? 101) <=> ($b['pct'] ?? 101); });
    }
}

if ($subject && isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="question-analysis-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Question ID', 'Question', 'Topic', 'Key', 'Answered', '% correct', 'A', 'B', 'C', 'D', 'Note']);
    foreach ($items as $it) {
        fputcsv($out, array_map('csv_cell', [$it['q']['id'], trim(strip_tags($it['q']['question_desc'])), $it['q']['topic'], $it['key'] ? chr(64 + $it['key']) : '?',
            $it['n'], $it['pct'], $it['c'][1] ?? 0, $it['c'][2] ?? 0, $it['c'][3] ?? 0, $it['c'][4] ?? 0, $it['flag']]));
    }
    exit;
}

admin_head('Question Analysis', 'analysis');
?>
<form class="form-inline" method="get" style="margin-bottom:15px;">
    <select name="subject" class="form-control" required>
        <option value="">Choose a subject</option>
        <?php foreach (admin_subject_options() as $s): ?>
            <option value="<?php echo (int) $s['sub_id']; ?>"<?php echo $subjectId === (int) $s['sub_id'] ? ' selected' : ''; ?>><?php echo e($s['class'] . ' — ' . trim($s['sub_name'])); ?></option>
        <?php endforeach; ?>
    </select>
    <label class="checkbox-inline"><input type="checkbox" name="sort" value="hard"<?php echo ($_GET['sort'] ?? '') === 'hard' ? ' checked' : ''; ?>> hardest first</label>
    <button class="btn btn-primary" type="submit"><i class="fa fa-bar-chart"></i> Analyse</button>
    <?php if ($subject): ?><button class="btn btn-success pull-right" type="submit" name="export" value="1"><i class="fa fa-download"></i> Export CSV</button><?php endif; ?>
</form>
<?php if (!$subject): ?>
    <p class="text-muted">Shows, for each question, how many students answered it, the percentage who got it right, and how many picked each option. Questions where most students chose the same wrong option are flagged, which usually means the answer key is wrong.</p>
<?php else:
    $flagged = count(array_filter($items, function ($i) { return $i['flag'] !== ''; })); ?>
    <p><strong><?php echo e($subject['class'] . ' — ' . trim($subject['sub_name'])); ?></strong>: <?php echo count($items); ?> question(s), <?php echo $flagged; ?> flagged.</p>
    <div class="panel panel-default"><div class="table-responsive">
    <table class="table table-striped table-tight" style="margin:0;">
        <thead><tr><th>#</th><th>Question</th><th>Key</th><th>Answered</th><th>% correct</th><th>A</th><th>B</th><th>C</th><th>D</th><th></th></tr></thead>
        <tbody>
        <?php if (!$items): ?><tr><td colspan="10" class="text-center text-muted">No questions for this subject.</td></tr><?php endif; ?>
        <?php foreach ($items as $it): ?>
            <tr<?php echo $it['flag'] ? ' class="warning"' : ''; ?>>
                <td><a href="question_edit.php?id=<?php echo (int) $it['q']['id']; ?>"><?php echo (int) $it['q']['id']; ?></a></td>
                <td style="max-width:360px;"><?php echo e(mb_substr(trim(strip_tags($it['q']['question_desc'])), 0, 110)); ?>
                    <?php if ($it['flag']): ?><br><small class="text-danger"><i class="fa fa-flag"></i> <?php echo e($it['flag']); ?></small><?php endif; ?></td>
                <td><strong><?php echo $it['key'] ? chr(64 + $it['key']) : '?'; ?></strong></td>
                <td><?php echo (int) $it['n']; ?></td>
                <td><?php echo $it['pct'] === null ? '-' : (int) $it['pct'] . '%'; ?></td>
                <?php for ($k = 1; $k <= 4; $k++): ?>
                    <td<?php echo $k === $it['key'] ? ' class="text-success"' : ''; ?>><?php echo (int) ($it['c'][$k] ?? 0); ?></td>
                <?php endfor; ?>
                <td></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
<?php endif; ?>
<?php admin_foot();
