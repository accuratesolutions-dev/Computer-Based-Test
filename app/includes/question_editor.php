<?php
/**
 * Rich-text question editor with an "Add picture" button (for maths diagrams, graphs, figures).
 * Echo question_editor_script('questionDescription') after the <textarea>. Pictures are uploaded to
 * uploads/questions by admin/image_upload.php and inserted into the question.
 */
function question_editor_tools()
{
    return '<div id="picTools" style="margin:6px 0 4px;">'
        . '<button type="button" class="btn btn-default btn-sm" id="picBtn"><i class="fa fa-picture-o"></i> Add picture</button> '
        . '<input type="file" id="picFile" accept="image/png,image/jpeg,image/gif" multiple style="display:none;">'
        . '<span id="picMsg" class="small text-muted" style="margin-left:8px;">Diagrams, graphs or figures (JPG, PNG, GIF). The picture is placed where your cursor is.</span>'
        . '</div>';
}

function question_editor_script($textareaId)
{
    $token = json_encode(csrf_token());
    $id = json_encode($textareaId);
    return <<<JS
<script>
(function () {
    var editor = CKEDITOR.replace($id, { height: 220 });
    var btn = document.getElementById('picBtn'), file = document.getElementById('picFile'), msg = document.getElementById('picMsg');
    function say(t, bad) { msg.textContent = t; msg.className = 'small ' + (bad ? 'text-danger' : 'text-success'); }
    btn.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () {
        var files = Array.prototype.slice.call(file.files), done = 0, failed = [];
        if (!files.length) { return; }
        btn.disabled = true; say('Uploading...', false);
        (function next() {
            var f = files.shift();
            if (!f) {
                btn.disabled = false; file.value = '';
                if (failed.length) { say(failed.join(' | '), true); } else { say(done + ' picture(s) added. You can drag the corner handles to resize them.', false); }
                return;
            }
            var fd = new FormData(); fd.append('csrf', $token); fd.append('pic', f);
            fetch('image_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { throw new Error('Session expired - reload the page.'); }); })
                .then(function (j) {
                    if (j.ok) { editor.insertHtml('<img src="../' + j.path + '" alt="" style="max-width:100%;">'); done++; }
                    else { failed.push(j.error || 'upload failed'); }
                })
                .catch(function (e) { failed.push(e.message || 'upload failed'); })
                .then(next);
        })();
    });
    // A picture pasted from the clipboard would arrive as inline data; ask for the button instead.
    editor.on('paste', function (ev) {
        if (ev.data && ev.data.dataValue && /src\s*=\s*["']data:/i.test(ev.data.dataValue)) {
            ev.cancel(); say('Pasted pictures are not supported - please use the Add picture button.', true);
        }
    });
})();
</script>
JS;
}
