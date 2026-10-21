<?php
require_once __DIR__ . '/../includes/admin_layout.php';

$dir = dirname(__DIR__) . '/uploads/questions';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
const IMG_MAX_BYTES = 500 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { http_response_code(403); exit('Invalid or expired form token. Reload the page and try again.'); }

    if (isset($_POST['delete'])) {
        $name = basename((string) ($_POST['name'] ?? ''));
        $path = $dir . '/' . $name;
        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $name) && $name[0] !== '.' && is_file($path)) {
            // Warn instead of silently breaking questions that use the picture.
            $used = (int) db_one("SELECT COUNT(*) AS c FROM question WHERE question_desc LIKE ?", 's', '%uploads/questions/' . addcslashes($name, '%_\\') . '%')['c'];
            if ($used > 0) {
                flash_set('danger', "\"$name\" is used by $used question(s), so it was not deleted. Edit or delete those questions first.");
            } else {
                @unlink($path);
                flash_set('success', 'Picture deleted.');
            }
        }
    } elseif (isset($_FILES['pics'])) {
        $ok = 0; $bad = [];
        $files = $_FILES['pics'];
        $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
        if (defined('IMAGETYPE_WEBP')) { $allowed[IMAGETYPE_WEBP] = 'webp'; }
        foreach ((array) $files['name'] as $i => $orig) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
            if ($files['error'][$i] !== UPLOAD_ERR_OK) { $bad[] = "$orig: upload failed"; continue; }
            if ($files['size'][$i] > IMG_MAX_BYTES) { $bad[] = "$orig: larger than 500 KB"; continue; }
            $info = @getimagesize($files['tmp_name'][$i]);
            if (!$info || !isset($allowed[$info[2]])) { $bad[] = "$orig: not a JPG, PNG or GIF picture"; continue; }
            $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo((string) $orig, PATHINFO_FILENAME)));
            $base = trim(substr($base, 0, 30), '-') ?: 'picture';
            $name = $base . '-' . bin2hex(random_bytes(2)) . '.' . $allowed[$info[2]];
            if (move_uploaded_file($files['tmp_name'][$i], $dir . '/' . $name)) { $ok++; } else { $bad[] = "$orig: could not be saved"; }
        }
        if ($ok) { flash_set('success', "$ok picture(s) uploaded. Copy the tag below into your question."); }
        if ($bad) { flash_set($ok ? 'warning' : 'danger', implode('; ', array_slice($bad, 0, 5))); }
        if (!$ok && !$bad) { flash_set('danger', 'Choose at least one picture.'); }
    }
    header('Location: images.php');
    exit;
}

$pics = [];
foreach (glob($dir . '/*.{jpg,png,gif,webp}', GLOB_BRACE) ?: [] as $f) {
    $pics[] = ['name' => basename($f), 'time' => filemtime($f), 'size' => filesize($f)];
}
usort($pics, function ($a, $b) { return $b['time'] <=> $a['time']; });

admin_head('Image library', 'images');
flash_show();
?>
<div class="row">
    <div class="col-md-4">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand">Add pictures</div>
            <div class="panel-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <div class="form-group"><input type="file" name="pics[]" accept="image/png,image/jpeg,image/gif" multiple class="form-control" required></div>
                    <button class="btn btn-success"><i class="fa fa-upload"></i> Upload</button>
                </form>
                <hr>
                <p class="small text-muted">JPG, PNG or GIF, up to 500 KB each (about <?php echo (int) ini_get('max_file_uploads'); ?> at a time). Keep diagrams small and clear &mdash; students see them on a lab screen.</p>
                <p class="small"><strong>Using a picture</strong></p>
                <ul class="small" style="padding-left:18px;">
                    <li><strong>Bulk upload:</strong> paste the tag into the <em>question</em> cell of your CSV.</li>
                    <li><strong>Single question:</strong> in the editor click the picture button, and type the address shown after <code>src=</code>.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="panel panel-default">
            <div class="panel-heading panel-heading-brand"><?php echo count($pics); ?> picture(s)</div>
            <?php if (!$pics): ?><div class="panel-body text-muted">No pictures yet.</div><?php endif; ?>
            <ul class="list-group">
            <?php foreach ($pics as $p): $tag = '<img src="uploads/questions/' . $p['name'] . '">'; ?>
                <li class="list-group-item clearfix">
                    <img src="<?php echo e(url('uploads/questions/' . rawurlencode($p['name']))); ?>" alt="" style="height:60px;max-width:110px;object-fit:contain;float:left;margin-right:14px;border:1px solid #ddd;background:#fff;">
                    <form method="post" class="pull-right" onsubmit="return confirm('Delete this picture?');">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="name" value="<?php echo e($p['name']); ?>">
                        <button class="btn btn-xs btn-danger" name="delete" value="1"><i class="fa fa-trash"></i></button>
                    </form>
                    <div class="input-group input-group-sm" style="max-width:520px;">
                        <input type="text" class="form-control tag-box" readonly value="<?php echo e($tag); ?>" onclick="this.select();">
                        <span class="input-group-btn"><button type="button" class="btn btn-default js-copy"><i class="fa fa-clipboard"></i> Copy</button></span>
                    </div>
                    <small class="text-muted"><?php echo e($p['name']); ?> &middot; <?php echo e(number_format($p['size'] / 1024, 0)); ?> KB</small>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('.js-copy'); if (!b) { return; }
    var box = b.closest('.input-group').querySelector('.tag-box'); box.select();
    try { document.execCommand('copy'); b.innerHTML = '<i class="fa fa-check"></i> Copied'; setTimeout(function () { b.innerHTML = '<i class="fa fa-clipboard"></i> Copy'; }, 1500); } catch (x) {}
});
</script>
<?php admin_foot();
