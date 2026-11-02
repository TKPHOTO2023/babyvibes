<?php
/**
 * Yoco webhook: Yoco calls this when a payment succeeds.
 * The signature is checked, the amount must match the saved order, and then the order is
 * marked paid and the confirmation emails go out. Each event is handled once.
 */

require __DIR__ . '/lib.php';

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	bv_json( array( 'error' => 'Method not allowed.' ), 405 );
}

$raw    = (string) file_get_contents( 'php://input' );
$secret = bv_config()['yoco_webhook_secret'];

$valid = bv_verify_webhook(
	$raw,
	$_SERVER['HTTP_WEBHOOK_ID'] ?? '',
	$_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? '',
	$_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '',
	$secret
);
if ( ! $valid ) {
	error_log( 'BabyVibes: rejected a webhook with a bad or missing signature.' );
	bv_json( array( 'error' => 'Invalid signature.' ), 403 );
}

$event   = json_decode( $raw, true );
$type    = $event['type'] ?? '';
$payment = $event['payload'] ?? array();

if ( 'payment.succeeded' !== $type ) {
	bv_json( array( 'ok' => true, 'ignored' => $type ) ); // Acknowledge other events.
}

$checkout_id = $payment['metadata']['checkoutId'] ?? '';
$order       = $checkout_id ? bv_find_order_by_checkout( $checkout_id ) : null;
if ( ! $order ) {
	error_log( "BabyVibes: payment for unknown checkout $checkout_id." );
	bv_json( array( 'ok' => true, 'unknown' => true ) );
}

if ( 'paid' === $order['status'] ) {
	bv_json( array( 'ok' => true, 'duplicate' => true ) );
}

$amount   = (int) ( $payment['amount'] ?? 0 );
$currency = $payment['currency'] ?? '';
if ( $amount !== (int) $order['total'] || 'ZAR' !== $currency ) {
	$order['status']  = 'amount_mismatch';
	$order['payment'] = array(
		'id'       => $payment['id'] ?? '',
		'amount'   => $amount,
		'currency' => $currency,
	);
	bv_save_order( $order );
	error_log( "BabyVibes: amount mismatch on {$order['id']}: paid $amount $currency, expected {$order['total']}." );
	bv_mail( bv_config()['orders_email'] ? bv_config()['orders_email'] : bv_shop()['email'], "Check order {$order['id']}: payment amount mismatch", "Yoco reported a payment of " . bv_rand( $amount ) . " $currency for order {$order['id']}, but the order total is " . bv_rand( $order['total'] ) . ". Please check it in the Yoco portal before sending anything." );
	bv_json( array( 'ok' => true ) );
}

$order['status']  = 'paid';
$order['paid']    = gmdate( 'c' );
$order['payment'] = array(
	'id'       => $payment['id'] ?? '',
	'amount'   => $amount,
	'currency' => $currency,
	'mode'     => $payment['mode'] ?? '',
);
$order = bv_save_order( $order );
bv_send_order_emails( $order );

bv_json( array( 'ok' => true ) );
