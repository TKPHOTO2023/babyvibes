# BabyVibes tests

`run.sh` installs a fresh test database, starts PHP's built-in server with `mock-yoco.php` loaded (a fake Yoco and
Brevo, emails to `mail.log`), and runs `e2e.js` in headless Chromium (Playwright).

Needs PHP 8 with pdo_sqlite (and pdo_mysql for MySQL), Node.js and Playwright with Chromium.

For the MySQL run, create the test database and user once:

```sql
CREATE DATABASE bvtest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bvuser'@'localhost' IDENTIFIED BY 'bv-test-pass';
GRANT ALL ON bvtest.* TO 'bvuser'@'localhost';
```

Set `BV_SHOTS=/some/folder` to save screenshots. Nothing in this folder belongs on the live site; the test pages
refuse to run unless the test kit is loaded, and `.htaccess` blocks the folder anyway.
