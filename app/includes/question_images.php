<?php
/**
 * Saving pictures for questions (uploads/questions). Used by the Image library and by the
 * "Add picture" button in the question editor.
 */

const QIMG_MAX_UPLOAD = 6291456;   // 6 MB straight from a phone/camera is fine; big ones are shrunk
const QIMG_MAX_STORED = 1048576;   // 1 MB once stored (keeps exam pages quick on a school network)
const QIMG_MAX_WIDTH  = 1000;

function qimg_dir()
{
    $dir = dirname(__DIR__) . '/uploads/questions';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir;
}

/**
 * Validates and stores one uploaded picture. Returns [fileName, ''] or ['', errorMessage].
 * JPG/PNG wider than 1000 px are scaled down (and phone rotation is corrected) when PHP's GD is available.
 */
function qimg_store($tmpPath, $origName, $size, $errorCode = UPLOAD_ERR_OK)
{
    if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
        return ['', 'larger than the server upload limit (' . ini_get('upload_max_filesize') . ')'];
    }
    if ($errorCode !== UPLOAD_ERR_OK) { return ['', 'upload failed']; }
    if ($size > QIMG_MAX_UPLOAD) { return ['', 'larger than 6 MB']; }
    $info = @getimagesize($tmpPath);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) { $types[IMAGETYPE_WEBP] = 'webp'; }
    if (!$info || !isset($types[$info[2]])) { return ['', 'not a JPG, PNG or GIF picture']; }
    $ext = $types[$info[2]];

    $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo((string) $origName, PATHINFO_FILENAME)));
    $base = trim(substr($base, 0, 30), '-') ?: 'picture';
    $name = $base . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = qimg_dir() . '/' . $name;

    $done = false;
    if (function_exists('imagecreatefromstring') && in_array($ext, ['jpg', 'png'], true)) {
        $img = @imagecreatefromstring((string) file_get_contents($tmpPath));
        if ($img) {
            if ($ext === 'jpg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($tmpPath);
                $rot = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
                if ($rot) { $r = imagerotate($img, $rot, 0); if ($r) { $img = $r; } }
            }
            $w = imagesx($img);
            if ($w > QIMG_MAX_WIDTH || $info[2] === IMAGETYPE_JPEG && filesize($tmpPath) > QIMG_MAX_STORED) {
                if ($w > QIMG_MAX_WIDTH) { $s = imagescale($img, QIMG_MAX_WIDTH); if ($s) { $img = $s; } }
                if ($ext === 'png') { imagealphablending($img, false); imagesavealpha($img, true); $done = imagepng($img, $dest, 6); }
                else { $done = imagejpeg($img, $dest, 85); }
            }
            imagedestroy($img);
        }
    }
    if (!$done) {
        $done = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
    }
    if (!$done || !is_file($dest)) { return ['', 'could not be saved (is the uploads/questions folder writable?)']; }
    if (filesize($dest) > QIMG_MAX_STORED) {
        @unlink($dest);
        return ['', 'still larger than 1 MB after saving - use a smaller picture' . (function_exists('imagecreatefromstring') ? '' : ' (the PHP "gd" extension would shrink it automatically)')];
    }
    return [$name, ''];
}
