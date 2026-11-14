<?php
/**
 * Yoco webhook: Yoco calls this when a payment succeeds.
 * The signature is checked, the amount must match the saved order, and then the order is
 * marked paid and the confirmation emails go out. Each payment is handled once.
 */

require __DIR__ . '/lib.php';

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	bv_fail( 'Method not allowed.', 405 );
}

$raw   = (string) file_get_contents( 'php://input' );
$valid = bv_verify_webhook(
	$raw,
	$_SERVER['HTTP_WEBHOOK_ID'] ?? '',
	$_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? '',
	$_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '',
	bv_webhook_secret()
);
if ( ! $valid ) {
	error_log( 'BabyVibes: rejected a webhook with a bad or missing signature.' );
	bv_fail( 'Invalid signature.', 403 );
}

$event   = json_decode( $raw, true );
$type    = $event['type'] ?? '';
$payment = $event['payload'] ?? array();
bv_setting( 'last_webhook_at', bv_now() . ' ' . $type );

// Yoco sends every payment on the account here (including card-machine sales), so only
// events for checkouts this shop created are acted on. Everything else is acknowledged.
$checkout_id = (string) ( $payment['metadata']['checkoutId'] ?? '' );
$order       = '' !== $checkout_id ? bv_find_order_by_checkout( $checkout_id ) : null;
if ( ! $order ) {
	bv_json( array( 'ok' => true, 'unknown' => true ) );
}

if ( 'payment.failed' === $type ) {
	if ( in_array( $order['status'], array( 'pending', 'checkout_failed' ), true ) ) {
		bv_order_event( $order['id'], 'payment_failed', 'A payment attempt failed (the card was declined or not completed). The shopper can try again.', false );
	}
	bv_json( array( 'ok' => true ) );
}

if ( 'refund.succeeded' === $type ) {
	// Claimed atomically, so a repeated event can't email the customer twice.
	if ( bv_update( 'orders', array( 'status' => 'refunded', 'cancelled_at' => bv_now(), 'updated_at' => bv_now() ), "id = ? AND status <> 'refunded'", array( $order['id'] ) ) ) {
		bv_order_event( $order['id'], 'refunded', 'Refunded ' . bv_rand( (int) ( $payment['amount'] ?? $order['total'] ) ) . ' to the card you paid with.' );
		$reason = bv_row( "SELECT message FROM order_events WHERE order_id = ? AND type = 'refund_reason' ORDER BY id DESC LIMIT 1", array( $order['id'] ) );
		bv_email_cancelled( bv_load_order( $order['id'] ), true, $reason ? $reason['message'] : '' );
	}
	bv_json( array( 'ok' => true ) );
}

if ( 'refund.failed' === $type ) {
	if ( 'refund_pending' === $order['status'] ) {
		$prev = bv_row( "SELECT message FROM order_events WHERE order_id = ? AND type = 'refund_requested' ORDER BY id DESC LIMIT 1", array( $order['id'] ) );
		$back = $prev && preg_match( '/previous status: (\w+)/', $prev['message'], $m ) && isset( BV_STATUSES[ $m[1] ] ) ? $m[1] : 'paid';
		bv_update_order( $order['id'], array( 'status' => $back ) );
	}
	$why = (string) ( $payment['failureReason'] ?? 'no reason given' );
	bv_order_event( $order['id'], 'refund_failed', "Yoco could not complete the refund ($why). Try again, or refund it in the Yoco portal.", false );
	$to = bv_config()['orders_email'] ? bv_config()['orders_email'] : bv_shop()['email'];
	bv_send_email( $to, 'BabyVibes', "Refund failed for order {$order['id']}", bv_email_layout( 'Refund failed', bv_eh( 'A refund failed' ) . bv_ep( 'Yoco could not complete the refund for order ' . bv_e( $order['id'] ) . ' (' . bv_e( $why ) . '). Please try again from the admin, or refund it in the Yoco portal.' ) ), "Yoco could not complete the refund for order {$order['id']} ($why). Try again from the admin, or refund it in the Yoco portal.", 'refund_failed', $order['id'] );
	bv_json( array( 'ok' => true ) );
}

if ( 'payment.succeeded' !== $type ) {
	bv_json( array( 'ok' => true, 'ignored' => $type ) );
}

$amount   = (int) ( $payment['amount'] ?? 0 );
$currency = $payment['currency'] ?? '';
$pay      = array(
	'payment_id'     => mb_substr( (string) ( $payment['id'] ?? '' ), 0, 64 ),
	'payment_amount' => $amount,
	'payment_mode'   => mb_substr( (string) ( $payment['mode'] ?? '' ), 0, 10 ),
);

// Mark paid only once, even if Yoco sends the event twice at the same moment.
$claimed = bv_update( 'orders', array_merge( $pay, array( 'status' => 'paid', 'paid_at' => bv_now(), 'updated_at' => bv_now() ) ), "id = ? AND status IN ('pending', 'checkout_failed') AND total = ? AND ? = 'ZAR'", array( $order['id'], $amount, $currency ) );

if ( $claimed ) {
	bv_order_event( $order['id'], 'paid', 'Payment received. Your order is being packed.' );
	$order = bv_load_order( $order['id'] );
	bv_email_order_confirmation( $order );
	bv_email_new_order_admin( $order );
	bv_json( array( 'ok' => true ) );
}

if ( in_array( $order['status'], array( 'pending', 'checkout_failed' ), true ) ) {
	// Still unpaid, so the amount or currency didn't match.
	bv_update_order( $order['id'], array_merge( $pay, array( 'status' => 'amount_mismatch' ) ) );
	bv_order_event( $order['id'], 'amount_mismatch', "Yoco reported a payment of " . bv_rand( $amount ) . " $currency, but the order total is " . bv_rand( $order['total'] ) . '. Check it in the Yoco portal before sending anything.', false );
	error_log( "BabyVibes: amount mismatch on {$order['id']}: paid $amount $currency, expected {$order['total']}." );
	$to = bv_config()['orders_email'] ? bv_config()['orders_email'] : bv_shop()['email'];
	bv_send_email( $to, 'BabyVibes', "Check order {$order['id']}: payment amount mismatch", bv_email_layout( 'Payment amount mismatch', bv_eh( 'Check this payment' ) . bv_ep( 'Yoco reported a payment of ' . bv_rand( $amount ) . ' ' . bv_e( $currency ) . ' for order ' . bv_e( $order['id'] ) . ', but the order total is ' . bv_rand( $order['total'] ) . '. Please check it in the Yoco portal before sending anything.' ) ), "Yoco reported a payment of " . bv_rand( $amount ) . " $currency for order {$order['id']}, but the order total is " . bv_rand( $order['total'] ) . '.', 'amount_mismatch', $order['id'] );
}

bv_json( array( 'ok' => true, 'duplicate' => true ) );
