<?php
/**
 * POST /api/track.php  { order, email }
 * Order tracking for everyone (guests too): the order number and the email used for the order
 * must match. Returns the status, history, items and courier tracking details.
 */

require __DIR__ . '/lib.php';

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	bv_fail( 'Method not allowed.', 405 );
}
bv_require_same_site();
if ( bv_rate_limited( 'track', bv_client_ip(), 30, 15 ) ) {
	bv_fail( 'Too many attempts. Please wait a few minutes and try again.', 429 );
}
bv_record_attempt( 'track', bv_client_ip() );

$in    = bv_input();
$order = bv_load_order( strtoupper( bv_clean( $in, 'order', 20 ) ) );
$email = strtolower( bv_clean( $in, 'email', 120 ) );
if ( ! $order || '' === $email || ! hash_equals( strtolower( $order['customer']['email'] ), $email ) ) {
	bv_fail( "We couldn't find an order with that order number and email. Check your order confirmation email.", 404 );
}
bv_json( bv_public_order( $order ) );
