<?php
// Top bar for student pages (not the exam page): who is signed in, one-click moves between pages, one-click log out.
// Expects $student (the register row).
?>
<nav class="navbar navbar-expand navbar-light bg-white border-bottom px-3 student-bar">
    <a class="navbar-brand d-flex align-items-center" href="subjects.php">
        <img src="images/akyerit-icon.png" alt="" style="height:30px;" class="mr-2"> <span class="d-none d-sm-inline">Akyerit Exams</span>
    </a>
    <span class="navbar-text mr-auto ml-2 text-dark small">
        <strong><?php outputTwo($student['surname'], $student['otherNames']); ?></strong>
        <span class="text-muted d-none d-md-inline">&middot; <?php output($student['examNumber']); ?> &middot; <?php getDepartment($student['department']); ?> (<?php echo e($student['level']); ?>)</span>
    </span>
    <a class="btn btn-sm btn-outline-secondary mr-2" href="instruction.php"><i class="fa fa-info-circle"></i> <span class="d-none d-sm-inline">Instructions</span></a>
    <a class="btn btn-sm btn-outline-secondary mr-2" href="subjects.php"><i class="fa fa-list"></i> <span class="d-none d-sm-inline">Subjects</span></a>
    <button type="button" class="btn btn-sm btn-danger" onclick="quitExam();"><i class="fa fa-sign-out"></i> <span class="d-none d-sm-inline">Log out</span></button>
</nav>
