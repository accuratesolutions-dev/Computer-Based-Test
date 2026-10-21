<?php
/**
 * Database backup as a plain .sql file (restore it with phpMyAdmin > Import). No mysqldump needed.
 * Streams table by table, so even a large exam database does not run out of memory.
 */
require_once __DIR__ . '/functions.php';

function backup_dir()
{
    $dir = dirname(__DIR__) . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function backup_write($out)
{
    $put = is_callable($out) ? $out : function ($s) use ($out) { fwrite($out, $s); };
    $put("-- Akyerit CBT backup " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    foreach (db_all("SHOW TABLES") as $row) {
        $table = (string) array_values($row)[0];
        if (!preg_match('/^\w+$/', $table)) {
            continue;
        }
        $create = db_one("SHOW CREATE TABLE `$table`");
        $ddl = (string) ($create['Create Table'] ?? array_values($create)[1] ?? '');
        $put("DROP TABLE IF EXISTS `$table`;\n$ddl;\n\n");

        $offset = 0;
        $chunk = 500;
        while (true) {
            $rows = db_all("SELECT * FROM `$table` LIMIT $chunk OFFSET $offset");
            if (!$rows) {
                break;
            }
            $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $values = [];
            foreach ($rows as $r) {
                $vals = [];
                foreach ($r as $v) {
                    $vals[] = $v === null ? 'NULL' : "'" . db_escape($v) . "'";
                }
                $values[] = '(' . implode(',', $vals) . ')';
            }
            $put("INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $values) . ";\n");
            $offset += $chunk;
            if (count($rows) < $chunk) {
                break;
            }
        }
        $put("\n");
    }
    $put("SET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
}

// Writes a backup file on the server and keeps only the newest $keep. Returns the file name.
function backup_to_server($keep = 14)
{
    $dir = backup_dir();
    $name = 'backup-' . date('Ymd-His') . '.sql';
    $fh = fopen($dir . '/' . $name, 'wb');
    if (!$fh) {
        throw new RuntimeException('Cannot write to the backups folder.');
    }
    backup_write($fh);
    fclose($fh);
    setting_set('last_backup', (string) time());

    $files = glob($dir . '/backup-*.sql') ?: [];
    rsort($files);
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
    return $name;
}

function backup_files()
{
    $files = glob(backup_dir() . '/backup-*.sql') ?: [];
    rsort($files);
    $out = [];
    foreach ($files as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    return $out;
}

// Called after an admin logs in: at most one automatic backup per ~20 hours. Never blocks the login.
function maybe_auto_backup()
{
    try {
        if (time() - (int) setting('last_backup', '0') > 20 * 3600) {
            backup_to_server();
        }
    } catch (Throwable $t) {
        error_log('Auto backup failed: ' . $t->getMessage());
    }
}
