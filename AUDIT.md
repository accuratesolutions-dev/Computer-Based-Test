# Code audit & what was changed

Original: PHP/MySQL CBT app built for XAMPP (hard-coded `root` / empty password, no deployment story).
It was not safe to put on the internet. Findings and fixes:

## Critical (fixed)

| # | Finding | Fix |
|---|---|---|
| 1 | **Student login granted admin access.** Students and admins shared `$_SESSION['id']`, so a student session passed the admin-page check, and the admin page can create admins. | Separate `student_id` / `admin_session` guards. |
| 2 | **Unauthenticated admin creation.** `admin/index.php` included the handler file that processes `addAdmin`, `updateStatus`, etc. with no login check. | Handlers only load behind an admin session; CSRF token required. |
| 3 | **Correct answers sent to the browser** (`truanswer[]` field on every question) and grading trusted client-side data. | Answers never leave the DB; grading is server-side only (`includes/grading.php`). |
| 4 | **SQL injection** across login, registration, subject lookups, exam pages (raw `$_GET/$_POST` in SQL; `addslashes`). | All queries use prepared statements. |
| 5 | **Hard-coded DB credentials** in 3 places, plus a committed SQL dump with real student records, passport photos, phone numbers and unsalted SHA-1 admin hashes. | Env-based config; PII/dump/photos removed; clean `db/schema.sql` + `seed.sql`. |
| 6 | **Insecure file upload** (extension check only, user-controlled filename, predictable path). | Content check via `getimagesize`, random filenames, size cap, script execution disabled in upload dirs. |
| 7 | `phpinfo.php` publicly accessible; DB errors echoed to users. | Removed; errors logged, generic message shown. |

## High / medium (fixed)

- Stored/reflected **XSS**: output is now escaped (`e()`); admin question HTML goes through `safe_html()`.
- **Admin passwords**: unsalted SHA-1 → `password_hash`; legacy hashes accepted once then upgraded.
- **Brute force**: per-IP throttling on student login, admin login and registration.
- **Session hardening**: HttpOnly, SameSite, Secure (when HTTPS), ID regenerated on login.
- **CSRF**: token on all admin actions and on exam submission.
- **Exam logic was incomplete/broken**: timer pointed at a non-existent `timer.php`, attempts weren't recorded,
  "End exam" never submitted, results page was debug output, `subjects.php` ignored Enabled/Disabled and
  showed one student's subjects from a URL parameter (IDOR), subject IDs were hard-coded by name.
  Now: server-enforced timer, one attempt per subject, proper result table, subject list built from the
  student's own registration + admin Enabled status.
- Registration picked the session user by *surname* (wrong user on duplicates) → uses the inserted ID;
  exam numbers use `random_int` and a unique index.
- MySQL 8 strict-mode compatibility (defaults on `NOT NULL` columns, utf8mb4, real DATETIME).
- Merge-conflict markers (`<<<<<<< HEAD`) in `adminLogout.php` removed; dead files deleted
  (`process.php`, `getUserTime.php`, stub `script.php` files, `.sass-cache`, Bootstrap docs source).

## Added in round 2

- **Student login now needs exam number + date of birth** (was exam number only). Failed attempts are throttled per
  IP (10 / 10 min) and per exam number (5 / 10 min), and the error message never says which field was wrong.
- **Auto-save**: each answer is saved to `exam_answer` as it is chosen (retries on network failure, warns before
  leaving with unsaved answers). Refresh/crash resumes with answers and remaining time intact. Timed-out attempts
  are graded from saved answers.
- **Admin pages**: Results (filters, stats, in-progress list, CSV export with formula-injection protection, reset
  attempt), Questions (list, edit with CKEditor, delete), Students (search, delete with cascade).

## Added in round 3

- **Bulk upload of questions** and **bulk registration of students** from CSV (validated first, all-or-nothing, duplicates skipped).
- **Automatic base-path detection** for sub-folder installs (XAMPP `htdocs/exam`).
- **Rebrand** to Akyerit Solutions, with a redrawn logo (replace `images/akyerit-logo.png` / `akyerit-icon.png` with the originals).

## Still open

1. **Outdated front-end libs**: CKEditor 4.9.2 (known XSS issues, admin-only surface), jQuery 3.2.1,
   Bootstrap 4.0.0 / 3.3.7. Upgrade when you can test the UI.
2. **Date of birth is a modest second factor**: classmates may know each other's birthdays. For high-stakes exams,
   issue a printed PIN per student instead.
3. Bulk question import is plain text only (no images/formatting); bulk-registered students have no passport photo.
4. Passport photos and results are personal data - apply your data-protection policy (NDPA/GDPR) to backups.

## Verification status

All PHP files pass `php -l` (PHP 8.3). I still could not run the app against a real MySQL server in my sandbox
(no database package available), so SQL and the browser flows are **reviewed but not executed**. Run this smoke test
on first deploy:

1. `docker compose up -d --build` -> `docker compose logs app` shows "Schema ready" and "Admin ... created".
2. `/admin/` login -> Upload Question (a few Biology questions, Science) -> Subject Status: enable Biology, 10 min.
3. `/onlineForm/` register (pick 9 subjects, upload a small jpg/png) -> note exam number and the DOB you entered.
4. `/` login: wrong DOB is rejected; correct exam number + DOB works.
5. START Biology, answer two questions, **refresh the page**: answers are still ticked, timer unchanged.
6. Submit -> score shown; START is now "COMPLETED". Admin -> Results shows it; Export CSV opens in Excel.
7. Start another attempt (second student), answer one question, close the tab, wait out the timer, open admin
   Results: the attempt appears, graded from the saved answer.
