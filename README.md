# Akyerit CBT (v4)

PHP/MySQL computer-based exam system for Junior (JSS1-3) and Senior (SSS) classes.

- `app/`, `docker/`, `Dockerfile`, `docker-compose*.yml`: full Docker deployment (see `DEPLOYMENT.md`).
- `exam-xampp/`: the same app folder, ready to copy to `htdocs\exam` on XAMPP.
- `windows-installer/`: builds a one-click Windows setup (.exe) for schools without IT staff (see its README).
- `UPDATE-NOTES.md`: what is new in v4 and how to upgrade.

The database upgrades itself on first page load. Database settings come from environment variables (`.env`, see `.env.example`); no passwords are stored in the code.
