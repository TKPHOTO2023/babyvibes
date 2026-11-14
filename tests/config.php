<?php
// Local testing only (see README, "Testing locally"). A fake Yoco, and SQLite by default.
// Set BV_TEST_DB=mysql to test against a local MySQL/MariaDB database instead (see tests/README.md).
$db = 'mysql' === getenv( 'BV_TEST_DB' )
	? array( 'dsn' => 'mysql:host=127.0.0.1;dbname=bvtest;charset=utf8mb4', 'user' => 'bvuser', 'password' => 'bv-test-pass' )
	: array( 'dsn' => 'sqlite:' . __DIR__ . '/test.db' );
return array(
	'site_url'            => 'http://127.0.0.1:8765',
	'db'                  => $db,
	'yoco_secret_key'     => 'sk_test_fake',
	'yoco_webhook_secret' => 'whsec_' . base64_encode( 'test-secret-key-0123456789abcdef' ),
	'brevo_api_key'       => 'xkeysib-test-only', // answered by the fake Brevo in mock-yoco.php
	'from_email'          => 'orders@example.test',
	'from_name'           => 'BabyVibes',
	'orders_email'        => 'owner@example.test',
	'admin_token'         => 'test-token-0123456789',
);
