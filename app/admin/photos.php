<?php
require_once __DIR__ . '/../includes/admin_layout.php';
require_admin_role();

const PHOTO_MAX_BYTES = 2097152; // 2 MB per photo
$photoDir = realpath(__DIR__ . '/../onlineForm/images/Student_Images') ?: (__DIR__ . '/../onlineForm/images/Student_Images');

// Validate image bytes and store them for one student. Returns '' on success or an error message.
function photo_store(array $student, $bytes, $photoDir)
{
    if ($bytes === false || $bytes === '' || strlen($bytes) > PHOTO_MAX_BYTES) { return 'larger than 2 MB or empty'; }
    $info = @getimagesizefromstring($bytes);
    $ext = $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'][$info[2]] ?? null) : null;
    if (!$ext) { return 'not a JPG or PNG image'; }
    if (!is_dir($photoDir) && !@mkdir($photoDir, 0755, true)) { return 'photo folder is not writable'; }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (@file_put_contents($photoDir . '/' . $name, $bytes) === false) { return 'could not save the file'; }
    $old = basename((string) $student['passport']);
    db_exec("UPDATE register SET passport = ? WHERE id = ?", 'si', 'images/Student_Images/' . $name, (int) $student['id']);
    if ($old !== '' && $old !== $name) {
        $f = realpath($photoDir . '/' . $old);
        if ($f && is_file($f) && strpos($f, realpath($photoDir)) === 0) { @unlink($f); }
    }
    return '';
}

$report = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }
    $ok = 0;
    $fail = [];
    $find = function ($exam) { return db_one("SELECT id, examNumber, passport FROM register WHERE examNumber = ?", 's', $exam); };

    if (isset($_POST['single'])) {
        $st = $find(trim((string) ($_POST['exam'] ?? '')));
        $f = $_FILES['photo'] ?? null;
        if (!$st) { $fail[] = 'Student not found.'; }
        elseif (!$f || $f['error'] !== UPLOAD_ERR_OK) { $fail[] = 'Please choose a photo.'; }
        else {
            $err = photo_store($st, file_get_contents($f['tmp_name']), $photoDir);
            $err === '' ? $ok++ : $fail[] = $st['examNumber'] . ': ' . $err;
        }
    } elseif (isset($_POST['bulk'])) {
        $files = $_FILES['photos'] ?? null;
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
                $exam = pathinfo((string) $name, PATHINFO_FILENAME);
                $st = $find($exam);
                if ($files['error'][$i] !== UPLOAD_ERR_OK) { $fail[] = $name . ': upload failed'; continue; }
                if (!$st) { $fail[] = $name . ': no student with exam number "' . $exam . '"'; continue; }
                $err = photo_store($st, file_get_contents($files['tmp_name'][$i]), $photoDir);
                $err === '' ? $ok++ : $fail[] = $name . ': ' . $err;
            }
        }
        $zip = $_FILES['zip'] ?? null;
        if ($zip && $zip['error'] === UPLOAD_ERR_OK) {
            if (!class_exists('ZipArchive')) { $fail[] = 'ZIP upload needs the PHP "zip" extension (enable extension=zip in php.ini), or select the photos directly.'; }
            else {
                $za = new ZipArchive();
                if ($za->open($zip['tmp_name']) !== true) { $fail[] = 'Could not open the ZIP file.'; }
                else {
                    $limit = min($za->numFiles, 1000);
                    for ($i = 0; $i < $limit; $i++) {
                        $st0 = $za->statIndex($i);
                        $base = basename((string) $st0['name']);
                        if ($base === '' || substr($st0['name'], -1) === '/' || $base[0] === '.' || !preg_match('/\.(jpe?g|png)$/i', $base)) { continue; }
                        if ($st0['size'] > PHOTO_MAX_BYTES) { $fail[] = $base . ': larger than 2 MB'; continue; }
                        $st = $find(pathinfo($base, PATHINFO_FILENAME));
                        if (!$st) { $fail[] = $base . ': no student with that exam number'; continue; }
                        $err = photo_store($st, $za->getFromIndex($i), $photoDir);
                        $err === '' ? $ok++ : $fail[] = $base . ': ' . $err;
                    }
                    $za->close();
                }
            }
        }
    }
    $_SESSION['photo_report'] = ['ok' => $ok, 'fail' => array_slice($fail, 0, 40), 'more' => max(0, count($fail) - 40)];
    header('Location: photos.php' . (isset($_GET['all']) ? '?all=1' : ''));
    exit;
}

$report = $_SESSION['photo_report'] ?? null;
unset($_SESSION['photo_report']);
$all = isset($_GET['all']);
$rows = db_all(
    "SELECT r.id, r.examNumber, r.surname, r.otherNames, r.level, r.passport, c.class FROM register r LEFT JOIN class c ON c.id = r.department"
    . ($all ? '' : " WHERE r.passport = '' OR r.passport LIKE '%students avatar%'") . " ORDER BY r.surname, r.otherNames LIMIT 300"
);
$missing = (int) db_one("SELECT COUNT(*) AS c FROM register WHERE passport = '' OR passport LIKE '%students avatar%'")['c'];

admin_head('Student Photos', 'photos');
if ($report) {
    echo '<div class="alert alert-' . ($report['ok'] ? 'success' : 'warning') . '"><strong>' . (int) $report['ok'] . ' photo(s) saved.</strong>';
    if ($report['fail']) {
        echo '<ul style="margin:6px 0 0;padding-left:18px;">';
        foreach ($report['fail'] as $m) { echo '<li>' . e($m) . '</li>'; }
        echo '</ul>' . ($report['more'] ? '...and ' . (int) $report['more'] . ' more.' : '');
    }
    echo '</div>';
}
?>
<div class="row">
    <div class="col-md-5">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">Upload many photos at once</div>
            <div class="panel-body">
                <p class="text-muted small">Name each photo after the student's exam number, e.g. <code>12345678TECH.jpg</code>. JPG or PNG, up to 2 MB each.
                   Select several files together, or upload one ZIP of photos.</p>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <div class="form-group"><label>Photos</label><input type="file" name="photos[]" multiple accept="image/jpeg,image/png" class="form-control"></div>
                    <div class="form-group"><label>or a ZIP file</label><input type="file" name="zip" accept=".zip" class="form-control"></div>
                    <button class="btn btn-success" name="bulk" value="1" type="submit"><i class="fa fa-upload"></i> Upload</button>
                </form>
                <p class="text-muted small" style="margin-top:10px;">Your server accepts at most <?php echo (int) ini_get('max_file_uploads'); ?> files per upload and <?php echo e(ini_get('upload_max_filesize')); ?> per file; use a ZIP for bigger batches.</p>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand"><?php echo $all ? 'All students' : $missing . ' student(s) without a photo'; ?>
                <a class="pull-right" style="color:#fff;" href="photos.php<?php echo $all ? '' : '?all=1'; ?>"><?php echo $all ? 'Show only missing' : 'Show all'; ?></a></div>
            <div class="table-responsive" style="max-height:520px;overflow:auto;">
            <table class="table table-striped table-tight" style="margin:0;">
                <tbody>
                <?php if (!$rows): ?><tr><td class="text-center text-muted">Everyone has a photo.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="width:50px;"><img src="<?php echo e(passport_url($r['passport'])); ?>" alt="" style="height:40px;width:40px;object-fit:cover;border-radius:4px;"></td>
                        <td><?php echo e($r['surname'] . ' ' . $r['otherNames']); ?><br><small class="text-muted"><?php echo e($r['examNumber'] . ' · ' . $r['class'] . ' ' . $r['level']); ?></small></td>
                        <td style="width:240px;">
                            <form method="post" enctype="multipart/form-data" class="form-inline">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="exam" value="<?php echo e($r['examNumber']); ?>">
                                <input type="file" name="photo" accept="image/jpeg,image/png" required style="width:150px;display:inline-block;font-size:11px;">
                                <button class="btn btn-xs btn-primary" name="single" value="1" type="submit">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?php admin_foot();
