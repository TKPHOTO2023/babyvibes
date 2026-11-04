<?php
// Local testing only (see README, "Testing locally"). Uses SQLite instead of MySQL and a fake Yoco.
return array(
	'site_url'            => 'http://127.0.0.1:8765',
	'db'                  => array( 'dsn' => 'sqlite:' . __DIR__ . '/test.db' ),
	'yoco_secret_key'     => 'sk_test_fake',
	'yoco_webhook_secret' => 'whsec_' . base64_encode( 'test-secret-key-0123456789abcdef' ),
	'brevo_api_key'       => '',
	'from_email'          => 'orders@example.test',
	'from_name'           => 'BabyVibes',
	'orders_email'        => 'owner@example.test',
	'admin_token'         => 'test-token-0123456789',
);
