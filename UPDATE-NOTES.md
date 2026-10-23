# Update notes (v4)

New in this version (all admin pages are under the grouped menu: Questions / Exams / Students / Settings):

- **Live monitor** (Exams): who is writing now, time left, questions answered, last auto-save. Refreshes every 5 seconds.
- **Report cards and class lists** (Exams > Report cards): per class and level, scores per subject, total, average, position (ties share a position), grade; Excel/CSV export; printable report card per student or the whole class.
- **Pass mark and grading scale** (Settings): editable; used by results, report cards and the student result page.
- **Question analysis** (Questions): % correct and option counts per question; flags a probable wrong answer key.
- **Exam cards** (Students): printable cards with exam number, date of birth and subjects; one-click link after bulk register.
- **Student photos** (Students): upload many photos named by exam number (or a ZIP); single upload per student.
- **Registration lock** (Settings): close self-registration now or at a set date and time.
- **Scheduled opening** (Subject Status, cog button): open and close a subject at set times.
- **Topic mix** per subject (e.g. 5 Algebra + 5 Geometry), optional topic column in the CSV upload.
- **Option shuffle** per subject (answers still grade correctly).
- **Image library** and safe <sup>/<sub>/<b>/<i> in questions (bulk upload and editor), for pictures and simple equations.
- **Admin accounts** (Settings): add, rename, reset password, remove.
- **System check and backups** (Settings > System): server check, exam-day rehearsal checklist, download a database backup, save backups on the server (an automatic one is taken after an admin login if the last is over 20 hours old; the newest 14 are kept).

Upgrading: copy the files over the old ones and open any page; the database upgrades itself (new columns, app_meta table). Nothing is deleted.

Not done: updating the bundled jQuery 3.2.1, Bootstrap 3.3.7/4.0.0 and CKEditor 4.9.2. They could not be downloaded in the build environment, so they are unchanged. The exam runs on a school network, but update them (jQuery 3.7+, CKEditor 4.22+) before exposing the site to the open internet.

Not tested: everything was tested against a SQLite stand-in for the database, not real MySQL/MariaDB. Do the rehearsal in Settings > System on the real server before exam day.

Earlier (v3): Junior class JSS1-3 with the school subject list, Marketing for Science/Art/Commercial, random question set per student, seamless navigation.

## v4.1
- **Level on every question** (JSS 1/2/3 for the Junior class, SSS 1/2/3 for Senior classes, or "All levels"): choose it when uploading a question, in Bulk upload and when editing. Students only get the questions for their own level plus the all-level ones. Existing questions stay "All levels". The question list has a level column and filter.
- **Add picture button** in the question editor (upload from your computer or phone; large pictures are shrunk automatically; JPG/PNG/GIF up to 6 MB). Subscript and superscript buttons are back in the editor for maths. Pasted (inline) pictures are not accepted; use the button.

## v4.2
- **Pause and reset time** (Exams > Live monitor, administrators): per student *Pause / Resume*, *+ min* (add minutes) and *reset time* (full time again, saved answers kept); plus *Pause everyone / Resume everyone* for emergencies. A paused student's screen is covered with a "Exam paused" message, their clock stops and the paused time is given back on resume. A paused student is never auto-submitted.
- **Teacher role** (Settings > Administrators & teachers): a teacher can upload/edit/clear questions, add pictures, see Question analysis, Results (view and export only) and the Live monitor (view only). Optionally tick the subjects a teacher may work on (none ticked = all subjects). Teachers cannot see student details, exam cards, settings, subject status or backups. Every person can change their own password under their name at the top right.
- **Clear questions** (Questions > All questions): deletes the questions of the chosen subject and level, or the whole bank (administrators). You must type DELETE; a backup is saved first; it is refused while students are writing; recorded results are kept.
- Upgrading: copy over the old files and open any page; the database upgrades itself.
