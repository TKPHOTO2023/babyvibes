#!/usr/bin/env bash
# Runs the BabyVibes end-to-end tests against a fresh test database.
#   tests/run.sh            SQLite
#   BV_TEST_DB=mysql tests/run.sh   local MySQL/MariaDB (database bvtest, user bvuser / bv-test-pass)
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
rm -rf tests/test.db tests/*.log tests/mail-html tests/yoco-webhooks.json tests/refund-fail
if [ "${BV_TEST_DB:-}" = "mysql" ]; then
	mysql -ubvuser -pbv-test-pass -h127.0.0.1 -e "DROP DATABASE IF EXISTS bvtest; CREATE DATABASE bvtest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null \
		|| mariadb -e "DROP DATABASE IF EXISTS bvtest; CREATE DATABASE bvtest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
fi
php -d auto_prepend_file="$ROOT/tests/mock-yoco.php" api/install.php --email=owner@example.test --password='test-admin-password-123' --name=Owner > /dev/null
PHP_CLI_SERVER_WORKERS=4 php -d auto_prepend_file="$ROOT/tests/mock-yoco.php" -S 127.0.0.1:8765 -t "$ROOT" > tests/server.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
sleep 1
NODE_PATH="${NODE_PATH:-$(npm root -g)}" node tests/e2e.js
if grep -qiE "PHP (Fatal|Warning|Notice|Deprecated)" tests/server.log; then
	echo "PHP reported problems:"; grep -iE "PHP (Fatal|Warning|Notice|Deprecated)" tests/server.log | head -20; exit 1
fi
echo "No PHP errors or warnings."
