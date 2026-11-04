<?php
/**
 * GET /api/order-status.php?order=<id>&t=<token>
 * Used by the thank-you page. Returns the order status and items only (no address or contact details).
 */

require __DIR__ . '/lib.php';

$order = bv_load_order( $_GET['order'] ?? '' );
if ( ! $order || ! hash_equals( $order['token'], (string) ( $_GET['t'] ?? '' ) ) ) {
	bv_fail( 'Order not found.', 404 );
}
bv_json( bv_public_order( $order ) );
