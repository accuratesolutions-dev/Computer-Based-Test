
<?php
require ('includes/config.php');
if (!registration_open()) {
    http_response_code(403);
    ?><!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration closed</title><link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css"><link rel="shortcut icon" href="images/akyerit-icon.png"></head>
    <body style="background:#f4f6f8;"><div class="container text-center" style="max-width:560px;margin-top:12vh;">
    <img src="images/akyerit-logo.png" alt="Akyerit Solutions" style="width:200px;" class="mb-4">
    <h2>Registration is closed</h2><p class="lead text-muted">Self-registration is not open right now. Please see your school administrator.</p>
    <a class="btn btn-success btn-lg" href="../index.php">Already registered? Go to exam login</a></div></body></html><?php
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
	<link rel="stylesheet" type="text/css" href="assets/bootstrap/css/bootstrap.min.css">
	<link rel="stylesheet" type="text/css" href="css/style.css">
	<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
	<title>Student registration portal</title>
	<link rel="shortcut icon"  href="images/akyerit-icon.png">
</head>
<body>
	<header>
	<div class="header-banner">
		<h2 class="text-center p-2">Akyerit Student registration form</h2>
		</div>
	</header>
	<div class="container">
		<div class="row">
			<div class="col-lg-8 offset-lg-2 col-sm-12">
			<div class="form-container" id="container">
				<!-- <form action="" method="post" enctype="multipart/form-data" autocomplete="on" > -->
				<fieldset class="scheduler-border">
				<legend class="scheduler-border ml-5">Personal Information:</legend>
				<div class="form-group">
				Surname: <input type="text" name="surname" id="surname" placeholder="" class="form-control" autofocus>
				Other names: <input type="text" name="otherNames" id="otherNames" placeholder="" class="form-control"  >
				Gender: <select name="gender" id="gender" class="form-control select ml-3 ">
					<option value="" selected="selected">Select...</option>
					<option value="Male">Male</option>
					<option value="Female">Female</option>
				</select>
				Phone no. : <input type="tel" name="phoneNumber" id="phoneNumber" placeholder="08031234567" inputmode="tel" class="form-control" >

			DOB: <input type="date" name="dateOfBirth" placeholder=" " id="dateOfBirth" class="form-control">

				</div>
			</fieldset>
			<fieldset class="scheduler-border">
				<legend class="scheduler-border ml-5">School Information:</legend>
				<div class="form-group">
				Class: <select name="department" id="department" class="form-control select ml-3" required>
					<option value="">Select class...</option>
					<?php echo class_options_html(); ?>
				</select>
				Level: <select name="level" id="level" class="form-control select ml-3" required>
					<option value="">Select class first...</option>
				</select>
				</div>
			</fieldset>
			<fieldset class="scheduler-border mb-3">
				<legend class="scheduler-border ml-5">Examination Information:</legend>
				<div class="form-group">
				<?php for ($n = 1; $n <= 9; $n++): ?>
				Subject<?php echo $n; ?>: <select name="subject<?php echo $n; ?>" id="subject<?php echo $n; ?>" class="form-control select ml-3 subject-select" required>
					<option value="">Select class first...</option>
				</select>
				<?php endfor; ?>
				<div id="formMessage" class="alert alert-danger mt-3" role="alert" style="display:none;"></div>
				<div class="text-center">
					<button type="button" name="submitForm" id="submitForm" class="mt-3">Proceed</button>
					<p class="mt-3 mb-0">Already registered? <a href="../index.php">Go to exam login</a></p>
				</div>
				</div>
			</fieldset>
			</div>
			</div>
		</div>
		<div class="text-center"><?php echo date("Y");?> &copy;Copyright Akyerit Solutions</div>
	</div>

<script src="js/jquery-3.2.1.min.js"></script>
<script src="assets/bootstrap/js/bootstrap.min.js"></script>
<script src="js/script.js"></script>
</body>
</html>
