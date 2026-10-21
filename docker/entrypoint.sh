#!/bin/sh
set -e
cd /var/www/html

echo "Waiting for database at ${DB_HOST:-db}:${DB_PORT:-3306} ..."
i=0
until php -r 'mysqli_report(MYSQLI_REPORT_OFF); $c=@mysqli_connect(getenv("DB_HOST"),getenv("DB_USER"),getenv("DB_PASS"),getenv("DB_NAME"),(int)(getenv("DB_PORT")?:3306)); exit($c?0:1);'; do
  i=$((i+1))
  if [ "$i" -ge 60 ]; then echo "Database not reachable after 60 tries, giving up." >&2; exit 1; fi
  sleep 2
done

php bin/init-db.php

if [ -n "$ADMIN_USER" ] && [ -n "$ADMIN_PASSWORD" ]; then
  # Only bootstrap when no admin exists yet, so restarts never overwrite a changed password.
  HAS_ADMIN=$(php -r 'require "includes/functions.php"; echo (int) db_one("SELECT COUNT(*) AS c FROM admin")["c"];')
  if [ "$HAS_ADMIN" = "0" ]; then
    php bin/create-admin.php "$ADMIN_USER" "$ADMIN_PASSWORD"
  fi
else
  echo "NOTE: ADMIN_USER / ADMIN_PASSWORD not set - create an admin with: docker compose exec app php bin/create-admin.php <user> <password>"
fi

exec docker-php-entrypoint "$@"
