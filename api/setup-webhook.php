<?php
/**
 * One-time setup: registers this shop's webhook with Yoco and saves the webhook secret.
 * Open https://your-shop/api/setup-webhook.php?token=<admin_token from config.php>
 * Run it again after you switch from the test key to the live key.
 */

define( 'BV_HTML', true );
require __DIR__ . '/lib.php';

header( 'Content-Type: text/plain; charset=utf-8' );
$config = bv_config();

if ( strlen( $config['admin_token'] ) < 16 || 'change-me-to-a-long-random-string' === $config['admin_token'] || ! hash_equals( $config['admin_token'], (string) ( $_GET['token'] ?? '' ) ) ) {
	http_response_code( 403 );
	exit( "Not allowed. Set a new admin_token in api/config.php and add ?token=... to the address.\n" );
}

$url = rtrim( $config['site_url'], '/' ) . '/api/webhook.php';
if ( 0 !== strpos( $url, 'https://' ) && ! preg_match( '#^http://(127\.0\.0\.1|localhost)[:/]#', $url ) ) { // http only for local testing
	exit( "site_url in api/config.php must start with https:// (Yoco only sends webhooks to secure addresses).\n" );
}

$mode = 0 === strpos( $config['yoco_secret_key'], 'sk_live_' ) ? 'live' : 'test';

// Remove earlier webhooks for this same address, so Yoco doesn't keep calling with an old secret.
list( $ls, $list ) = bv_yoco_request( 'GET', '/webhooks' );
if ( 401 === $ls || 403 === $ls ) {
	http_response_code( 502 );
	exit( "Yoco rejected the secret key (HTTP $ls). Check yoco_secret_key in api/config.php.\n" );
}
foreach ( (array) ( $list['subscriptions'] ?? array() ) as $sub ) {
	if ( isset( $sub['url'], $sub['id'] ) && rtrim( $sub['url'], '/' ) === $url ) {
		bv_yoco_request( 'DELETE', '/webhooks/' . rawurlencode( $sub['id'] ) );
		echo "Removed the old webhook {$sub['id']} ({$sub['mode']} mode).\n";
	}
}
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

bv_setting( 'yoco_webhook_secret', $body['secret'] );
bv_setting( 'yoco_webhook_info', json_encode( array( 'id' => $body['id'] ?? '', 'mode' => $body['mode'] ?? $mode, 'url' => $url, 'created' => gmdate( 'c' ) ) ) );

echo "Done. Yoco will notify $url about payments ($mode mode).\n";
echo "The webhook secret is saved; there's nothing to copy.\n";
if ( ! empty( $config['yoco_webhook_secret'] ) ) {
	echo "\nNote: api/config.php also has a yoco_webhook_secret, which takes priority. Clear it so the new one is used.\n";
}
