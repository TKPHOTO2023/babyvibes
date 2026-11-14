<?php
/** Local testing only: sends a webhook signed the way Yoco signs them (Standard Webhooks, v1 HMAC-SHA256). */
defined( 'BV_YOCO_MOCK' ) || exit( 'Test file.' );

function bv_test_send_webhook( $type, $checkout, $amount, array $extra = array() ) {
	$secret = 'whsec_' . base64_encode( 'test-secret-key-0123456789abcdef' );
	$status = array( 'payment.succeeded' => 'succeeded', 'payment.failed' => 'failed', 'refund.succeeded' => 'succeeded', 'refund.failed' => 'failed' )[ $type ] ?? 'succeeded';
	$body   = json_encode(
		array(
			'id'          => 'evt_' . bin2hex( random_bytes( 6 ) ),
			'type'        => $type,
			'createdDate' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'payload'     => array_merge(
				array(
					'id'       => ( 0 === strpos( $type, 'refund' ) ? 'rf_' : 'p_' ) . bin2hex( random_bytes( 6 ) ),
					'amount'   => $amount,
					'currency' => 'ZAR',
					'status'   => $status,
					'mode'     => 'test',
					'metadata' => array( 'checkoutId' => $checkout ),
				),
				$extra
			),
		)
	);
	$id  = 'msg_' . bin2hex( random_bytes( 4 ) );
	$ts  = (string) time();
	$sig = 'v1,' . base64_encode( hash_hmac( 'sha256', "$id.$ts.$body", base64_decode( substr( $secret, 6 ) ), true ) );
	$ch  = curl_init( 'http://127.0.0.1:8765/api/webhook.php' );
	curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', "webhook-id: $id", "webhook-timestamp: $ts", "webhook-signature: $sig" ) ) );
	$res  = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( $code, $res );
}

// Called directly by the test runner: tests/webhook-sender.php?type=refund.succeeded&checkout=ch_...&amount=12345
if ( basename( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === basename( __FILE__ ) ) {
	header( 'Content-Type: application/json' );
	$extra = isset( $_GET['reason'] ) ? array( 'failureReason' => (string) $_GET['reason'] ) : array();
	list( $code, $res ) = bv_test_send_webhook( (string) $_GET['type'], preg_replace( '/[^\w]/', '', (string) $_GET['checkout'] ), (int) $_GET['amount'], $extra );
	echo json_encode( array( 'status' => $code, 'response' => json_decode( (string) $res, true ) ) );
}
