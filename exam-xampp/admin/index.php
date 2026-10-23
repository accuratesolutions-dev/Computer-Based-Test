<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/backup.php';
start_secure_session();
ensure_migrated();

if (!empty($_SESSION['admin_id'])) {
    redirect('admin/question_portal.php');
}

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adminLogin'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_check()) {
        $errorMessage = "<div class='alert alert-danger'><strong>Session expired. Reload and try again.</strong></div>";
    } elseif ($username === '' || $password === '') {
        $errorMessage = "<div class='alert alert-warning'><strong>All fields are required!</strong></div>";
    } elseif (login_blocked('admin')) {
        http_response_code(429);
        $errorMessage = "<div class='alert alert-danger'><strong>Too many attempts. Try again in a few minutes.</strong></div>";
    } else {
        $admin = db_one("SELECT id, Username, Password, role FROM admin WHERE Username = ? LIMIT 1", 's', $username);
        $ok = false;
        if ($admin) {
            $stored = $admin['Password'];
            if (strpos($stored, '$') === 0) {
                $ok = password_verify($password, $stored);
            } else {
                // Legacy unsalted SHA-1 hash from the original schema: accept once, then upgrade.
                $ok = hash_equals($stored, sha1($password));
                if ($ok) {
                    db_exec("UPDATE admin SET Password = ? WHERE id = ?", 'si', password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']);
                }
            }
        }
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['username'] = $admin['Username'];
            $_SESSION['role'] = $admin['role'] === 'teacher' ? 'teacher' : 'admin';
            login_cleared('admin');
            maybe_auto_backup(); // at most one automatic database backup a day
            redirect('admin/question_portal.php');
        }
        login_failed('admin');
        $errorMessage = "<div class='alert alert-danger'><strong>Invalid Login credentials!</strong></div>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Administrator Login Page</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" media="screen" href="../css/admin_index.css">
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="shortcut icon" href="../images/akyerit-icon.png" type="image/x-icon">
</head>
<body>
    <div class="grid-container">
        <div class="jumbotron"><h1>Administrator Portal</h1></div>
        <div class="container">
            <div class="row">
                <div class="col-lg-6 offset-lg-6 col-md-12 col-sm-12 col-xs-12">
                    <div class="aside-bar">
                        <form action="" id="login-form" method="post">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <h4 class="form-heading">Login to Continue...</h4>
                            <p id="errorMessage"><?php echo $errorMessage; ?></p>
                            <div class="input-section" id="formContainer">
                                <div class="form-input">
                                    <input type="text" name="username" id="username" placeholder="Your Username" autofocus autocomplete="username">
                                </div>
                                <div class="form-input2">
                                    <input type="password" name="password" id="password" placeholder=" Your Password" autocomplete="current-password">
                                </div>
                                <center><button type="submit" name="adminLogin" id="adminLogin">Login</button></center>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
