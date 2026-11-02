<?php
/**
 * One-time setup: registers this shop's webhook with Yoco and saves the webhook secret.
 * Open https://your-shop/api/setup-webhook.php?token=<admin_token from config.php>
 * Run it again after you switch from the test key to the live key.
 */

require __DIR__ . '/lib.php';

header( 'Content-Type: text/plain; charset=utf-8' );
$config = bv_config();

if ( 'change-me-to-a-long-random-string' === $config['admin_token'] || ! hash_equals( $config['admin_token'], (string) ( $_GET['token'] ?? '' ) ) ) {
	http_response_code( 403 );
	exit( "Not allowed. Set a new admin_token in api/config.php and add ?token=... to the address.\n" );
}

$url = rtrim( $config['site_url'], '/' ) . '/api/webhook.php';
if ( 0 !== strpos( $url, 'https://' ) ) {
	exit( "site_url in api/config.php must start with https:// (Yoco only sends webhooks to secure addresses).\n" );
}

$mode = 0 === strpos( $config['yoco_secret_key'], 'sk_live_' ) ? 'live' : 'test';
list( $status, $body ) = bv_yoco_request(
	'POST',
	'/webhooks',
	array(
		'name' => 'babyvibes-' . $mode . '-' . gmdate( 'Ymd-His' ),
		'url'  => $url,
	)
);

if ( $status < 200 || $status >= 300 || empty( $body['secret'] ) ) {
	http_response_code( 502 );
	exit( "Yoco said no (HTTP $status):\n" . json_encode( $body, JSON_PRETTY_PRINT ) . "\n\nCheck yoco_secret_key in api/config.php.\n" );
}

file_put_contents(
	bv_orders_dir() . '/.webhook.json',
	json_encode(
		array(
			'id'      => $body['id'] ?? '',
			'mode'    => $mode,
			'url'     => $url,
			'secret'  => $body['secret'],
			'created' => gmdate( 'c' ),
		)
	),
	LOCK_EX
);

echo "Done. Yoco will notify $url about payments ($mode mode).\n";
echo "The webhook secret is saved; there's nothing to copy.\n";
if ( ! empty( $config['yoco_webhook_secret'] ) ) {
	echo "\nNote: api/config.php also has a yoco_webhook_secret, which takes priority. Clear it so the new one is used.\n";
}
