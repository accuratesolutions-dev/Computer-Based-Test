<?php
require_once __DIR__ . '/includes/session.php';
$userid = (int) $_SESSION['student_id'];
$fetch = db_one("SELECT * FROM register WHERE id = ?", 'i', $userid);
if (!$fetch) {
    redirect('includes/logout.php');
}
$_SESSION['seen_instructions'] = true; // after this, logging in again goes straight to the subject list
$student = $fetch;
include __DIR__ . '/includes/header.php';
?>

<body class="index_body">
	<?php include __DIR__ . '/includes/student_bar.php'; ?>
	<div class="container">
		<div class="row">
			<div class="col-lg-3 col-md-6 col-sm-12">
				<div class="card mt-5" style="padding: 0px;" >
				
				
					<img src="<?php echo e(passport_url($fetch['passport'])); ?>" class="card-img-top" alt="<?php echo e($fetch['surname'] . ' ' . $fetch['otherNames']); ?>">
					<div class="card-body">
						<div class="card-title text-center p-2 text-dark" ><h6><?php outputTwo($fetch['surname'],$fetch['otherNames']); ?></h6></div>
							<p>Phone No: <?php output($fetch['phoneNumber']); ?> </p>
							<p>Reg No: <?php output($fetch['examNumber']); ?></p>
							<p>Class: <?php getDepartment($fetch['department']); ?></p>
							<p>Level: <?php output($fetch['level']); ?></p>
						
					</div>
				</div>
				<?php

				?>
			</div>
			<div class="col-lg-9 col-sm-12 take-in"> 
				<div class="custom mt-5 hide">
				
					<h3 class="" id="list-subject" >Please follow the Instructions Giving below before proceeding.</h3>
					<hr>
					<ul class="mt-5 p-0">
					<?php foreach (getAllInstruction() as $ins): ?>
						<li style="font-size: 1rem;"><?php echo nl2br(e($ins['text'])); ?></li>
					<?php endforeach; ?>
					</ul>
					
					<a href="subjects.php" class="btn btn-info btn-lg btn-block" id="proceed">PROCEED TO SUBJECTS &nbsp;&nbsp;<i class="fa fa-arrow-right"></i></a>
					
				</div>
			</div>
		</div>
	</div>



<?php include ('includes/footer.php'); ?>
<script src="includes/quitExam.js"></script>

