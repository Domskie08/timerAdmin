-- TimerAdmin MySQL bootstrap
-- Import this file first, then run:
-- php artisan migrate --seed
--
-- Update the password below before using this in production.

CREATE DATABASE IF NOT EXISTS `timer_admin`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'timeradmin'@'127.0.0.1'
    IDENTIFIED WITH mysql_native_password BY 'change_me_strong_password';

CREATE USER IF NOT EXISTS 'timeradmin'@'localhost'
    IDENTIFIED WITH mysql_native_password BY 'change_me_strong_password';

ALTER USER 'timeradmin'@'127.0.0.1'
    IDENTIFIED WITH mysql_native_password BY 'change_me_strong_password';

ALTER USER 'timeradmin'@'localhost'
    IDENTIFIED WITH mysql_native_password BY 'change_me_strong_password';

GRANT ALL PRIVILEGES ON `timer_admin`.* TO 'timeradmin'@'127.0.0.1';
GRANT ALL PRIVILEGES ON `timer_admin`.* TO 'timeradmin'@'localhost';

FLUSH PRIVILEGES;
