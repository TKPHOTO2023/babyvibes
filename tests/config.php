<?php
return array(
	'site_url'            => 'http://127.0.0.1:8765',
	'yoco_secret_key'     => 'sk_test_fake',
	'yoco_webhook_secret' => 'whsec_' . base64_encode( 'test-secret-key-0123456789abcdef' ),
	'admin_token'         => 'test-token',
	'orders_email'        => '',
	'from_email'          => 'orders@example.test',
	'orders_dir'          => __DIR__ . '/orders',
);
