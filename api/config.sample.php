<?php
/**
 * BabyVibes server settings. Copy this file to config.php (same folder) and fill it in.
 * config.php holds your passwords and secret keys: it is never committed to git and never
 * served to visitors (api/.htaccess blocks it).
 */
return array(
	// The full web address of the shop, without a trailing slash.
	'site_url'        => 'https://babyvibes.example.co.za',

	// MySQL database: create it in cPanel > MySQL Databases (see README, "Going live").
	'db'              => array(
		'dsn'      => 'mysql:host=localhost;dbname=cpaneluser_babyvibes;charset=utf8mb4',
		'user'     => 'cpaneluser_bvuser',
		'password' => 'the-database-password',
	),

	// Yoco: Yoco portal > Sales > Payment gateway (or Developers) > Keys.
	// Use the TEST secret key (sk_test_...) until a test order works, then the LIVE key.
	'yoco_secret_key' => 'sk_test_REPLACE_ME',

	// Saved automatically when you open api/setup-webhook.php. Leave empty.
	'yoco_webhook_secret' => '',

	// Brevo (emails): Brevo > SMTP & API > API keys > Generate a new API key (starts with xkeysib-).
	'brevo_api_key'   => 'xkeysib-REPLACE_ME',

	// Emails come from this address. It must be on your own domain and the domain must be
	// verified in Brevo (Senders, domains & dedicated IPs > Domains), or emails go to spam.
	'from_email'      => 'orders@babyvibes.example.co.za',
	'from_name'       => 'BabyVibes',

	// New-order and new-review alerts go here. Empty = the email in data/shop.json.
	'orders_email'    => '',

	// A long random password (at least 16 characters) that protects api/install.php and
	// api/setup-webhook.php. Change it.
	'admin_token'     => 'change-me-to-a-long-random-string',
);
