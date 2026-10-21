// "End exam" = submit the answers given so far; the server grades them.
function ask() {
    swal({
        title: "Are you sure you want to end the exam?",
        text: "Your answers will be submitted and scored immediately. You cannot retake this subject.",
        icon: "warning",
        buttons: true,
        dangerMode: true,
    }).then(function (confirmed) {
        if (confirmed) {
            document.getElementById("examForm").submit();
        }
    });
}
