<?php
/**
 * BabyVibes server settings. Copy this file to config.php (same folder) and fill it in.
 * config.php holds your secret keys: it is never committed to git and never served to visitors.
 */
return array(
	// The full web address of the shop, without a trailing slash.
	'site_url'       => 'https://babyvibes.example.co.za',

	// Yoco keys: Yoco app or portal > Sales > Payment gateway (or Developers) > Keys.
	// Use the TEST secret key (sk_test_...) until you've done a test order, then switch to the LIVE key.
	'yoco_secret_key' => 'sk_test_REPLACE_ME',

	// Filled in automatically when you open api/setup-webhook.php once (see README).
	// You can also paste the webhook secret (whsec_...) here yourself.
	'yoco_webhook_secret' => '',

	// A long random password that protects api/setup-webhook.php. Change it.
	'admin_token'    => 'change-me-to-a-long-random-string',

	// Order notifications go here. Leave empty to use the email in data/shop.json.
	'orders_email'   => '',

	// The "From" address for order emails. Use an address on your own domain so emails aren't marked as spam.
	'from_email'     => 'orders@babyvibes.example.co.za',

	// Where orders are saved. The default folder is protected by api/orders/.htaccess.
	// A folder outside public_html is even safer, for example '/home/youruser/babyvibes-orders'.
	'orders_dir'     => __DIR__ . '/orders',
);
