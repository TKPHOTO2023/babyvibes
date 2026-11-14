<?php
/**
 * Local testing only: loaded with php -d auto_prepend_file. Replaces Yoco and Brevo with fakes and
 * writes emails to tests/mail.log instead of sending them. Never upload this to the live site.
 * The fake Yoco follows the request and response formats of Yoco's Checkout API.
 */
define( 'BV_CONFIG', __DIR__ . '/config.php' );
define( 'BV_MAIL_LOG', __DIR__ . '/mail.log' );
if ( ! is_dir( __DIR__ . '/mail-html' ) ) {
	mkdir( __DIR__ . '/mail-html' );
}
define( 'BV_MAIL_HTML_DIR', __DIR__ . '/mail-html' );
define( 'BV_YOCO_MOCK', 'bv_test_yoco' );
define( 'BV_BREVO_MOCK', 'bv_test_brevo' );

function bv_test_yoco( $method, $path, $body ) {
	file_put_contents( __DIR__ . '/yoco.log', json_encode( array( $method, $path, $body ) ) . "\n", FILE_APPEND );
	$hooks_file = __DIR__ . '/yoco-webhooks.json';
	$hooks      = is_readable( $hooks_file ) ? json_decode( file_get_contents( $hooks_file ), true ) : array();

	if ( 'POST' === $method && '/checkouts' === $path ) {
		if ( ! is_int( $body['amount'] ?? null ) || $body['amount'] < 200 || 'ZAR' !== ( $body['currency'] ?? '' ) || empty( $body['successUrl'] ) || empty( $body['cancelUrl'] ) ) {
			return array( 400, array( 'message' => 'Missing or incorrect value was provided' ) );
		}
		$id = 'ch_test' . substr( md5( json_encode( $body ) . microtime() ), 0, 12 );
		return array( 200, array( 'id' => $id, 'status' => 'created', 'amount' => $body['amount'], 'currency' => 'ZAR', 'processingMode' => 'test', 'redirectUrl' => 'http://127.0.0.1:8765/tests/fake-yoco.php?checkout=' . $id . '&amount=' . $body['amount'] . '&success=' . rawurlencode( $body['successUrl'] ) . '&cancel=' . rawurlencode( $body['cancelUrl'] ) ) );
	}
	if ( 'POST' === $method && preg_match( '#^/checkouts/(ch_\w+)/refund$#', $path, $m ) ) {
		if ( null !== $body ) {
			return array( 400, array( 'message' => 'Missing or incorrect value was provided for field unknown' ) );
		}
		if ( is_readable( __DIR__ . '/refund-fail' ) ) {
			return array( 400, array( 'message' => 'Refund not allowed', 'displayMessage' => 'This payment cannot be refunded yet.' ) );
		}
		return array( 200, array( 'id' => $m[1], 'refundId' => 'rf_' . substr( md5( $m[1] ), 0, 10 ), 'status' => 'created', 'message' => 'Refund requested' ) );
	}
	if ( 'GET' === $method && '/webhooks' === $path ) {
		return array( 200, array( 'subscriptions' => array_values( array_map( function ( $h ) { unset( $h['secret'] ); return $h; }, $hooks ) ) ) );
	}
	if ( 'DELETE' === $method && preg_match( '#^/webhooks/(\w+)$#', $path, $m ) ) {
		unset( $hooks[ $m[1] ] );
		file_put_contents( $hooks_file, json_encode( $hooks ) );
		return array( 204, array() );
	}
	if ( 'POST' === $method && '/webhooks' === $path ) {
		$id           = 'wh_test' . substr( md5( microtime() ), 0, 8 );
		$hooks[ $id ] = array( 'id' => $id, 'name' => $body['name'], 'url' => $body['url'], 'mode' => 'test', 'secret' => 'whsec_' . base64_encode( 'test-secret-key-0123456789abcdef' ) );
		file_put_contents( $hooks_file, json_encode( $hooks ) );
		return array( 201, $hooks[ $id ] );
	}
	return array( 404, array( 'message' => 'not mocked' ) );
}

function bv_test_brevo( $method, $path ) {
	if ( '/account' === $path ) {
		return array( 200, array( 'email' => 'owner@example.test', 'companyName' => 'Hostdigi (Pty) Ltd', 'plan' => array( array( 'type' => 'free', 'credits' => 300, 'creditsType' => 'sendLimit' ) ) ) );
	}
	if ( '/senders' === $path ) {
		return array( 200, array( 'senders' => array( array( 'id' => 1, 'name' => 'BabyVibes', 'email' => 'orders@example.test', 'active' => true, 'ips' => array() ) ) ) );
	}
	if ( 0 === strpos( $path, '/senders/domains' ) ) {
		return array( 200, array( 'domains' => array( array( 'id' => 'd1', 'domain_name' => 'example.test', 'authenticated' => true, 'verified' => true ) ), 'count' => 1 ) );
	}
	return array( 404, array() );
}
