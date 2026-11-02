<?php
/** Local testing only: stands in for Yoco's payment page. "Pay" sends a signed payment.succeeded webhook. */
$checkout = preg_replace( '/[^\w]/', '', $_GET['checkout'] ?? '' );
$amount   = (int) ( $_GET['amount'] ?? 0 );
$success  = $_GET['success'] ?? '/';
if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$secret = 'whsec_' . base64_encode( 'test-secret-key-0123456789abcdef' );
	$body   = json_encode( array( 'id' => 'evt_test', 'type' => 'payment.succeeded', 'payload' => array( 'id' => 'p_test', 'amount' => $amount, 'currency' => 'ZAR', 'status' => 'succeeded', 'mode' => 'test', 'metadata' => array( 'checkoutId' => $checkout ) ) ) );
	$id     = 'msg_' . bin2hex( random_bytes( 4 ) );
	$ts     = (string) time();
	$sig    = 'v1,' . base64_encode( hash_hmac( 'sha256', "$id.$ts.$body", base64_decode( substr( $secret, 6 ) ), true ) );
	$ch     = curl_init( 'http://127.0.0.1:8765/api/webhook.php' );
	curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', "webhook-id: $id", "webhook-timestamp: $ts", "webhook-signature: $sig" ) ) );
	curl_exec( $ch );
	header( 'Location: ' . $success );
	exit;
}
?><!doctype html><meta charset="utf-8"><title>Fake Yoco</title>
<h1>Fake Yoco payment page (test)</h1><p>Amount: R<?php echo number_format( $amount / 100, 2 ); ?></p>
<form method="post"><button id="fake-pay">Pay</button></form>
