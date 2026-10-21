// Student login: one click (or Enter), clear inline messages, straight on to the exam.
$(function () {
    var $btn = $("#login");
    var $error = $("#error-message");
    var $empty = $("#empty_notice");
    var busy = false;

    function showError(text) {
        $empty.hide();
        $error.text(text).stop(true, true).fadeIn(200);
    }

    function submit() {
        if (busy) { return; }
        var regNumber = $.trim($("#regNumber").val());
        var dob = $("#dob").val();
        $error.hide();
        if (regNumber === "" || dob === "") {
            $empty.stop(true, true).fadeIn(200);
            return;
        }
        $empty.hide();
        busy = true;
        $btn.prop("disabled", true).text("PLEASE WAIT...");
        $.ajax({
            url: "includes/script.php",
            type: "post",
            data: { regNumber: regNumber, dob: dob },
            cache: false
        }).done(function (response) {
            response = $.trim(response);
            if (response === "1") {
                $btn.text("WELCOME...");
                window.location = "instruction.php";
                return;
            }
            if (response === "2") { showError("Wrong exam number or date of birth. Please check and try again."); $("#dob").val(""); }
            else if (response === "empty") { $empty.fadeIn(200); }
            else { showError(response); }
            busy = false;
            $btn.prop("disabled", false).text("LOGIN");
        }).fail(function (xhr) {
            showError(xhr.responseText && xhr.status === 429 ? xhr.responseText : "Could not reach the server. Check the connection and try again.");
            busy = false;
            $btn.prop("disabled", false).text("LOGIN");
        });
    }

    $("#loginForm").on("submit", function (e) { e.preventDefault(); submit(); });
    $("#regNumber").trigger("focus");
});
