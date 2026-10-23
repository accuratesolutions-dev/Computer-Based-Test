<?php
// Shared chrome for admin pages (Bootstrap 3 navbar). Requires an admin session.
require_once __DIR__ . '/admin_session.php';

function admin_head($title, $active = '', $extraHead = '')
{
    // Menu groups: label => [icon, [key => [file, label, icon]]]
    $groups = [
        'Questions' => ['fa-question-circle', [
            'portal'    => ['question_portal.php', 'Upload a question', 'fa-plus'],
            'questions' => ['questions.php', 'All questions', 'fa-list'],
            'import'    => ['question_import.php', 'Bulk upload (CSV)', 'fa-upload'],
            'images'    => ['images.php', 'Image library', 'fa-picture-o'],
            'analysis'  => ['analysis.php', 'Question analysis', 'fa-line-chart'],
        ]],
        'Exams' => ['fa-pencil-square-o', [
            'subjects'  => ['exam_control.php', 'Subject status', 'fa-toggle-on'],
            'monitor'   => ['monitor.php', 'Live monitor', 'fa-heartbeat'],
            'results'   => ['results.php', 'Results', 'fa-bar-chart'],
            'reports'   => ['report_cards.php', 'Report cards', 'fa-file-text-o'],
        ]],
        'Students' => ['fa-users', [
            'students'  => ['students.php', 'All students', 'fa-users'],
            'register'  => ['student_import.php', 'Bulk register', 'fa-user-plus'],
            'photos'    => ['photos.php', 'Photos', 'fa-camera'],
            'cards'     => ['exam_cards.php', 'Exam cards (print)', 'fa-id-card-o'],
        ]],
        'Settings' => ['fa-cog', [
            'settings'  => ['settings.php', 'Grading, registration, instructions', 'fa-sliders'],
            'system'    => ['system.php', 'System check & backup', 'fa-database'],
        ]],
    ];
    if (!is_admin_role()) {   // teachers: questions, results and the live monitor only
        $teacherKeys = ['portal', 'questions', 'import', 'images', 'analysis', 'monitor', 'results'];
        foreach ($groups as $label => $g) {
            $groups[$label][1] = array_intersect_key($g[1], array_flip($teacherKeys));
            if (!$groups[$label][1]) { unset($groups[$label]); }
        }
    }
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($title); ?> &mdash; Administrator</title>
    <link rel="stylesheet" href="<?php echo e(url('dist/css/bootstrap.min.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(url('assets/font-awesome-4.7.0/css/font-awesome.min.css')); ?>">
    <link rel="shortcut icon" href="<?php echo e(url('images/akyerit-icon.png')); ?>" type="image/x-icon">
    <style>
        body { padding-top: 70px; background: #f6f8fa; }
        .page-title { color: #243665; margin: 0 0 20px; }
        .panel-heading-brand { background: #243665 !important; color: #fff !important; }
        .stat { font-size: 26px; font-weight: 600; color: #449D44; }
        .table-tight td, .table-tight th { vertical-align: middle !important; }
        .q-snippet { max-width: 420px; }
        .navbar-nav > li > a { padding-left: 11px; padding-right: 11px; }
        .status-open { color: #449D44; font-weight: 600; }
        .status-closed { color: #999; }
    </style>
<?php echo $extraHead; ?>
</head>
<body>
<nav class="navbar navbar-default navbar-fixed-top" role="navigation">
    <div class="container-fluid">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#adminNav" aria-expanded="false">
                <span class="sr-only">Toggle navigation</span><span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span>
            </button>
            <a href="question_portal.php" class="navbar-brand"><img src="<?php echo e(url('images/akyerit-icon.png')); ?>" alt="" style="height:24px;display:inline-block;margin-top:-4px;"> Akyerit Solutions</a>
        </div>
        <div class="collapse navbar-collapse" id="adminNav">
            <ul class="nav navbar-nav">
                <?php foreach ($groups as $label => $g):
                    $isActive = isset($g[1][$active]); ?>
                    <li class="dropdown<?php echo $isActive ? ' active' : ''; ?>">
                        <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
                            <i class="fa <?php echo e($g[0]); ?>"></i> <?php echo e($label); ?> <span class="caret"></span></a>
                        <ul class="dropdown-menu">
                            <?php foreach ($g[1] as $key => $it): ?>
                                <li<?php echo $key === $active ? ' class="active"' : ''; ?>><a href="<?php echo e($it[0]); ?>"><i class="fa <?php echo e($it[2]); ?> fa-fw"></i> <?php echo e($it[1]); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
            <ul class="nav navbar-nav navbar-right">
                <li><a href="account.php" title="My account"><i class="fa fa-user"></i> <?php echo e($_SESSION['username'] ?? ''); ?> <small class="text-muted">(<?php echo is_admin_role() ? 'admin' : 'teacher'; ?>)</small></a></li>
                <li><a href="<?php echo e(url('includes/adminLogout.php')); ?>"><i class="fa fa-sign-out"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
<div class="container-fluid">
    <h2 class="page-title"><?php echo e($title); ?></h2>
<?php
}

function admin_foot()
{
    ?>
</div>
<script src="<?php echo e(url('assets/js/jquery-3.2.1.min.js')); ?>"></script>
<script src="<?php echo e(url('dist/js/bootstrap.min.js')); ?>"></script>
</body>
</html>
<?php
}

// All subjects with their class name, for filter dropdowns.
function admin_subject_options()
{
    return db_all("SELECT s.sub_id, s.sub_name, s.class_id, c.class FROM subject s JOIN class c ON c.id = s.class_id WHERE " . subject_scope_sql('s.sub_id') . " ORDER BY c.id, s.sub_name");
}

// Prevent spreadsheet formula injection in CSV exports.
function csv_cell($v)
{
    $v = (string) $v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
}

function flash_set($type, $text) { $_SESSION['admin_flash'] = ['type' => $type, 'text' => $text]; }
function flash_show()
{
    if (!empty($_SESSION['admin_flash'])) {
        $f = $_SESSION['admin_flash'];
        unset($_SESSION['admin_flash']);
        echo '<div class="alert alert-' . e($f['type']) . '">' . e($f['text']) . '</div>';
    }
}
