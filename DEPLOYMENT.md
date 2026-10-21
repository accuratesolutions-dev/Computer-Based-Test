# CBT Software – Deployment Guide

PHP 8.2 + Apache + MySQL 8, packaged with Docker. Configuration is 100% environment variables
(no credentials in code).

## 1. Run it (local or any VPS with Docker)

```bash
cp .env.example .env
# edit .env: set DB_PASS, DB_ROOT_PASSWORD, ADMIN_USER, ADMIN_PASSWORD (min 10 chars)
docker compose up -d --build
```

On first start the container waits for MySQL, creates the tables, loads the starter classes/subjects/
instructions, and creates the first admin from `ADMIN_USER` / `ADMIN_PASSWORD` (only if no admin exists).

| URL | Purpose |
|---|---|
| `http://localhost:8080/onlineForm/` | Student registration (gets an exam number + passport photo upload) |
| `http://localhost:8080/` | Student exam login (exam number **and** date of birth) |
| `http://localhost:8080/admin/` | Admin login |

Admin menu: **Upload Question**, **Questions** (search, edit, delete), **Bulk Upload** (questions from CSV),
**Results** (filter, CSV export, reset a student's attempt), **Students** (search, export CSV, delete),
**Bulk Register** (students from CSV).

Smoke test: set `SEED_DEMO=1` before the **first** start to load 3 sample Biology questions
(Science class).

## 2. Production (HTTPS on a VPS)

1. Point a DNS A record (e.g. `exams.example.com`) at the server; open ports 80 and 443.
2. Add `DOMAIN=exams.example.com` to `.env`.
3. `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`

Caddy obtains and renews the TLS certificate automatically; the app is no longer exposed on 8080,
and secure cookies are forced. (The `!reset` in the overlay needs Docker Compose ≥ 2.24.)

Other hosts: the image runs on any container platform (Render, Railway, Fly.io, ECS...). Provide a
managed MySQL, set the `DB_*` variables, `TRUST_PROXY=1`, `FORCE_SECURE_COOKIES=1`, and mount a
persistent volume at `/var/www/html/onlineForm/images/Student_Images` (passport photos).

## 3. Without Docker (LAMP / cPanel)

1. Requirements: PHP ≥ 8.0 with `mysqli`, Apache with `mod_rewrite`/`mod_headers` and `AllowOverride All`
   (the shipped `.htaccess` files protect internal files and uploads).
2. Upload the contents of `app/` to the web root. Make `onlineForm/images/Student_Images` writable.
3. Create a MySQL database + user, then set environment variables for PHP/Apache
   (`DB_HOST, DB_NAME, DB_USER, DB_PASS`, optional `APP_TIMEZONE`, `APP_BASE_PATH`):
   `SetEnv DB_HOST localhost` etc. in the vhost, or `php-fpm` pool `env[...]`.
4. Initialise: `php bin/init-db.php && php bin/create-admin.php <user> <password>`
5. Serve over HTTPS and set `FORCE_SECURE_COOKIES=1`.
6. If the app lives in a sub-folder (e.g. `/exam`), set `APP_BASE_PATH=/exam`.

## 3b. Windows / XAMPP notes

- Copy the *contents* of `app/` to `C:\xampp\htdocs\exam\`. The app detects its own folder (`/exam`) automatically;
  set `APP_BASE_PATH` only if you want to override it.
- Apache "Port 80 in use ... PID 4": Windows' own web service holds port 80. Either stop *World Wide Web Publishing
  Service* (services.msc), or move Apache to port 8081 (Config -> Service and Port Settings, and `Listen`/`ServerName`
  in httpd.conf; SSL to 4433) and browse to `http://localhost:8081/exam/`.
- XAMPP's defaults (user `root`, empty password, database `tri-exam`) match the app's defaults, so no configuration is needed.
  Create the database `tri-exam` in phpMyAdmin first, then run `php bin/init-db.php` and `php bin/create-admin.php <user> <password>`.

## 4. Running an exam

1. Admin → **Add question(s)** per class/subject (mark the correct option A–D).
2. Students register at `/onlineForm/` and print their photocard (exam number).
3. Admin → **Subject Status**: set the subject to *Enabled* and give a duration **in minutes**.
4. Students log in with their **exam number + the date of birth they registered with**, read the
   instructions, press START on an enabled subject.
5. The server runs the clock. Every answer is **auto-saved** the moment it is chosen; if the page is
   refreshed or the computer crashes, the student logs back in, presses START again and resumes with
   their answers restored and the same remaining time. Answers are submitted at zero; each subject can be
   taken once. If a student never returns, the attempt is graded from the saved answers when an admin opens
   **Results** (or run `php bin/finalize-expired.php` from cron).
6. Admin -> **Results** shows scores, pass rate, who is in progress, and exports CSV. **Reset** deletes a
   result so a student can retake that subject.

Results are stored in `exam_result`. Raw SQL, if you prefer:
```bash
docker compose exec db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e \
 "SELECT r.examNumber, r.surname, s.sub_name, e.correct, e.total, e.percent FROM exam_result e
  JOIN register r ON r.id=e.student_id JOIN subject s ON s.sub_id=e.subject_id ORDER BY r.surname"'
```

## 5. Upgrading an existing install

`docker compose up -d --build` is enough: the entrypoint re-runs `db/schema.sql` (idempotent) and creates the new
`exam_answer` table. No data is touched.

## 6. Operations

- **Backup:** `docker compose exec db sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > backup.sql`
  and back up the `passports` volume.
- **Reset an admin password:** `docker compose exec app php bin/create-admin.php <user> <new-password>`
- **Logs:** `docker compose logs -f app`. Set `APP_DEBUG=1` only temporarily when diagnosing.
- **Update:** `git pull && docker compose up -d --build` (data lives in the named volumes).

## 7. Environment variables

| Var | Default | Notes |
|---|---|---|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | localhost / 3306 / tri-exam / root / (empty) | compose sets these |
| `ADMIN_USER` / `ADMIN_PASSWORD` | – | first-admin bootstrap (Docker) |
| `APP_TIMEZONE` | Africa/Lagos | used for exam timing |
| `APP_BASE_PATH` | (empty) | sub-folder deployments |
| `TRUST_PROXY` | 0 | 1 behind a reverse proxy (real client IP for rate limits) |
| `FORCE_SECURE_COOKIES` | 0 | 1 when served over HTTPS |
| `SEED_DEMO` | 0 | load sample questions once |
| `APP_DEBUG` | 0 | show errors (never in production) |
