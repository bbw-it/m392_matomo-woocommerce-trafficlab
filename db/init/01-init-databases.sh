#!/bin/bash
# Wird beim ERSTEN Start von MariaDB ausgeführt (/docker-entrypoint-initdb.d).
# Legt beide Datenbanken + Benutzer an. Die Passwörter kommen aus den
# Umgebungsvariablen (aus .env) – NICHT hartkodiert, damit Container und
# DB-Benutzer nie auseinanderlaufen ("Access denied").
#
# Hinweis: Init-Scripts laufen NUR auf einem frischen Daten-Volume. Wer in .env
# nachträglich Passwörter ändert, muss einmal zurücksetzen:
#   docker compose down -v && docker compose up -d
set -euo pipefail

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${WP_DB_NAME}\`     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${MATOMO_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS '${WP_DB_USER}'@'%'     IDENTIFIED BY '${WP_DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${MATOMO_DB_USER}'@'%' IDENTIFIED BY '${MATOMO_DB_PASSWORD}';
-- Selbstheilend: setzt das Passwort auch dann korrekt, wenn der Benutzer schon existiert.
ALTER USER '${WP_DB_USER}'@'%'     IDENTIFIED BY '${WP_DB_PASSWORD}';
ALTER USER '${MATOMO_DB_USER}'@'%' IDENTIFIED BY '${MATOMO_DB_PASSWORD}';

GRANT ALL PRIVILEGES ON \`${WP_DB_NAME}\`.*     TO '${WP_DB_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${MATOMO_DB_NAME}\`.* TO '${MATOMO_DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

echo "[db-init] Datenbanken '${WP_DB_NAME}' und '${MATOMO_DB_NAME}' + Benutzer angelegt (Passwörter aus .env)."
