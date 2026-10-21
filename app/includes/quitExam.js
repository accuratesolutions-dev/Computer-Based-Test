// One confirmation, then straight to the login page.
function quitExam() {
    swal({
        title: "Log out?",
        text: "Your finished subjects are already saved. You can log back in any time with your exam number and date of birth.",
        icon: "warning",
        buttons: ["Stay", "Log out"],
        dangerMode: true
    }).then(function (confirmed) {
        if (confirmed) {
            window.location = "includes/logout.php";
        }
    });
}
