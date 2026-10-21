// Registration form: class -> levels + subjects, no duplicate subjects, inline messages (no pop-ups).
$(function () {
    var SUBJECT_COUNT = 9;
    var $msg = $('#formMessage');
    var $btn = $('#submitForm');

    function showMessage(text, type) {
        $msg.removeClass('alert-danger alert-success alert-info').addClass('alert-' + (type || 'danger')).text(text).show();
        $('html, body').animate({ scrollTop: $msg.offset().top - 150 }, 200);
    }
    function clearMessage() { $msg.hide().text(''); }

    function fillSelect($sel, placeholder, items) {
        $sel.empty().append($('<option>').val('').text(placeholder));
        items.forEach(function (it) { $sel.append($('<option>').val(it).text(it)); });
    }

    // Hide a subject in the other dropdowns once it has been chosen, so nobody picks it twice.
    function syncSubjects() {
        var chosen = {};
        $('.subject-select').each(function () { if (this.value) { chosen[this.value] = true; } });
        $('.subject-select').each(function () {
            var mine = this.value;
            $(this).find('option').each(function () {
                var taken = this.value && chosen[this.value] && this.value !== mine;
                this.disabled = !!taken;
                this.hidden = !!taken;
            });
        });
    }
    $(document).on('change', '.subject-select', syncSubjects);

    $('#department').on('change', function () {
        clearMessage();
        var id = this.value;
        if (!id) {
            fillSelect($('#level'), 'Select class first...', []);
            $('.subject-select').each(function () { fillSelect($(this), 'Select class first...', []); });
            return;
        }
        $.getJSON('includes/subjects.php', { 'class': id }).done(function (d) {
            if (!d.ok) { showMessage('Could not load that class. Please try again.'); return; }
            fillSelect($('#level'), 'Select level...', d.levels);
            $('.subject-select').each(function (i) { fillSelect($(this), 'Select subject ' + (i + 1) + '...', d.subjects); });
            if (d.subjects.length < SUBJECT_COUNT) {
                showMessage('This class only has ' + d.subjects.length + ' subjects set up. Ask the administrator to add more before you register.');
            }
        }).fail(function () { showMessage('Could not load subjects. Check your connection and try again.'); });
    });

    $btn.on('click', function () {
        clearMessage();
        var data = {
            surname: $.trim($('#surname').val()),
            otherNames: $.trim($('#otherNames').val()),
            gender: $('#gender').val(),
            dateOfBirth: $('#dateOfBirth').val(),
            phoneNumber: $.trim($('#phoneNumber').val()),
            department: $('#department').val(),
            level: $('#level').val()
        };
        var checks = [
            [data.surname === '', 'Please enter your surname.', '#surname'],
            [data.otherNames === '', 'Please enter your other names.', '#otherNames'],
            [data.gender === '', 'Please select your gender.', '#gender'],
            [data.phoneNumber === '', 'Please enter your phone number.', '#phoneNumber'],
            [data.dateOfBirth === '', 'Please enter your date of birth.', '#dateOfBirth'],
            [!data.department, 'Please select your class.', '#department'],
            [!data.level, 'Please select your level.', '#level']
        ];
        for (var i = 0; i < checks.length; i++) {
            if (checks[i][0]) { showMessage(checks[i][1]); $(checks[i][2]).focus(); return; }
        }
        var seen = {};
        for (var n = 1; n <= SUBJECT_COUNT; n++) {
            var v = $('#subject' + n).val();
            if (!v) { showMessage('Please select subject ' + n + '.'); $('#subject' + n).focus(); return; }
            if (seen[v]) { showMessage('"' + v + '" was selected twice. Choose a different subject.'); $('#subject' + n).focus(); return; }
            seen[v] = true;
            data['subject' + n] = v;
        }

        $btn.prop('disabled', true).text('Registering...');
        $.ajax({ url: 'includes/insertData.php', method: 'POST', data: data, dataType: 'text' })
            .done(function () {
                showMessage('Registration successful! Opening your photocard...', 'success');
                window.location = 'output.php';
            })
            .fail(function (xhr) {
                showMessage(xhr.responseText || 'Could not submit the form. Please try again.');
                $btn.prop('disabled', false).text('Proceed');
            });
    });
});
