-- Wird beim ERSTEN Start von MariaDB ausgeführt (/docker-entrypoint-initdb.d).
-- Legt beide Datenbanken + Benutzer an. Werte müssen zu .env passen.
CREATE DATABASE IF NOT EXISTS `wordpress` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `matomo`    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'wp'@'%'     IDENTIFIED BY 'wp_change_me';
CREATE USER IF NOT EXISTS 'matomo'@'%' IDENTIFIED BY 'matomo_change_me';

GRANT ALL PRIVILEGES ON `wordpress`.* TO 'wp'@'%';
GRANT ALL PRIVILEGES ON `matomo`.*    TO 'matomo'@'%';
FLUSH PRIVILEGES;
