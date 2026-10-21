<?php
require_once __DIR__ . '/includes/functions.php';
start_secure_session();
ensure_migrated();
if (!empty($_SESSION['student_id'])) {
    // Already signed in: go back to where they were (instructions are shown once per login).
    redirect(!empty($_SESSION['seen_instructions']) ? 'subjects.php' : 'instruction.php');
}
include __DIR__ . '/includes/header.php';
?>
<title>Exam Login page</title>
<link rel="stylesheet" href="css/indexstyle.css">
<body>
        
    <div class="container-page">
<?php // include_once ('includes/script.php'); ?>
                    <div class="container">
                        <div class="row">
                        <div class="col-lg-6 offset-lg-5 col-md-6  offset-md-4 col-sm-4 offset-sm-3">
                                    <div class="content-header">
                                            
                                                     <img src="images/akyerit-logo.png" alt="Akyerit Solutions" class="img-fluid" style="width:220px;height:auto;" >
                                
                                    </div>
                                    </div> 
                                </div>
                                <div class="row">
                        <div class="col-lg-6 offset-lg-3 col-md-8 offset-md-2 col-sm-11 col-xs-12">
                        <h4 class="text-center text-danger" id="error-message"></h4>
                        <h4 class="text-center text-danger" id="empty_notice"><span>Please Fill Required Fields</span></h4>
                                <div class="wrap-content">
                                
                                        <form action="" id="loginForm" method="post" enctype="multipart/form-data">
                                        <br/>
                                        
                                        <center><h3>Login to Examination Portal</h3> </center>
                                                                <div class="input-group mb-3 mt-3" >
                        <div class="input-group-prepend" >
                        <span class="input-group-text" id="basic-addon1" style="background-color: #94CDC5;"><i class="fa fa-user" style="color: #8A82A8;"></i></span>
                        </div>
                        <input type="text"  id="regNumber"   class="form-control" placeholder="Exam Number (e.g. 12345678TECH)" autocomplete="off">
                        </div>
                                        <div class="input-group mb-3" >
                        <div class="input-group-prepend" >
                        <span class="input-group-text" style="background-color: #94CDC5;"><i class="fa fa-calendar" style="color: #8A82A8;"></i></span>
                        </div>
                        <input type="date" id="dob" class="form-control" placeholder="Date of Birth" title="Date of birth you registered with" autocomplete="bday">
                        </div>
                                        <center>
                                        <button type="submit" class="btn btn-success btn-submit" id="login">LOGIN</button>
                                        </center>
                                        </form>
                                        </div>      
                                        </div>  
                                </div>
                <div class="row">
                        <div class="col-lg-6 offset-lg-3 col-md-8 offset-md-2 col-sm-11 col-xs-12">
                        
                          <center><p>New student? <a href="onlineForm/">Register here</a></p>
                          <p> <?php echo date("Y");?> &copy;Copyright Akyerit Solutions</p></center>
                        </div>                    
                </div>
                                                         
                        </div>
    </div>


   
   
        <?php include ('includes/footer.php'); ?>
        <script src="includes/script.js"></script>
       
       

