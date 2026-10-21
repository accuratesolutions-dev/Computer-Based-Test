<?php
require_once __DIR__ . '/session.php';

$id = (int) $_SESSION['student_id'];
$file = $_FILES['userPassport'] ?? null;

if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    echo 'Error in Uploading!';
    exit;
}
if ($file['size'] > 150000) {
    echo '<h3>Image too large to save! Please refresh the page and upload a new image</h3>';
    exit;
}

// Trust the file's actual content, not its name or client-sent type.
$info = @getimagesize($file['tmp_name']);
$map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
if (!$info || !isset($map[$info[2]])) {
    echo 'You cannot upload Image of this type';
    exit;
}

$dir = __DIR__ . '/../images/Student_Images/';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$name = bin2hex(random_bytes(12)) . '.' . $map[$info[2]];
if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
    echo 'Error in Uploading!';
    exit;
}

$old = db_one("SELECT passport FROM register WHERE id = ?", 'i', $id);
$rel = 'images/Student_Images/' . $name;
db_exec("UPDATE register SET passport = ? WHERE id = ?", 'si', $rel, $id);
if ($old && $old['passport'] !== '') {
    $oldFile = realpath($dir . basename($old['passport']));
    if ($oldFile && strpos($oldFile, realpath($dir)) === 0) {
        @unlink($oldFile);
    }
}
?>
<img class="img-thumbnail image-preview" src="<?php echo e($rel); ?>" alt="Passport"/>
