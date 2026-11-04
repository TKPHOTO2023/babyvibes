<?php
/**
 * Local testing only: loaded with php -d auto_prepend_file. Replaces Yoco with a fake and writes
 * emails to tests/mail.log instead of sending them. Never upload this to the live site.
 */
define( 'BV_CONFIG', __DIR__ . '/config.php' );
define( 'BV_MAIL_LOG', __DIR__ . '/mail.log' );
if ( ! is_dir( __DIR__ . '/mail-html' ) ) {
	mkdir( __DIR__ . '/mail-html' );
}
define( 'BV_MAIL_HTML_DIR', __DIR__ . '/mail-html' );
define( 'BV_YOCO_MOCK', 'bv_test_yoco' );

function bv_test_yoco( $method, $path, $body ) {
	file_put_contents( __DIR__ . '/yoco.log', json_encode( array( $method, $path, $body ) ) . "\n", FILE_APPEND );
	if ( '/checkouts' === $path ) {
		$id = 'ch_test' . substr( md5( json_encode( $body ) ), 0, 10 );
		return array( 200, array( 'id' => $id, 'status' => 'created', 'redirectUrl' => 'http://127.0.0.1:8765/tests/fake-yoco.php?checkout=' . $id . '&amount=' . $body['amount'] . '&success=' . rawurlencode( $body['successUrl'] ) ) );
	}
	if ( '/webhooks' === $path ) {
		return array( 200, array( 'id' => 'sub_test', 'secret' => 'whsec_' . base64_encode( 'test-secret-key-0123456789abcdef' ) ) );
	}
	return array( 404, array( 'message' => 'not mocked' ) );
}
