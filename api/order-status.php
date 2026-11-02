<?php
/**
 * GET /api/order-status.php?order=<id>&t=<token>
 * Used by the thank-you page. Returns the order status and items only (no address or contact details).
 */

require __DIR__ . '/lib.php';

$order = bv_load_order( $_GET['order'] ?? '' );
if ( ! $order || ! hash_equals( $order['token'], (string) ( $_GET['t'] ?? '' ) ) ) {
	bv_json( array( 'error' => 'Order not found.' ), 404 );
}

bv_json(
	array(
		'id'        => $order['id'],
		'status'    => $order['status'],
		'firstName' => $order['customer']['firstName'],
		'lines'     => array_map(
			function ( $l ) {
				return array( 'name' => $l['name'], 'qty' => $l['qty'], 'total' => $l['total'] );
			},
			$order['lines']
		),
		'delivery'  => $order['delivery'],
		'total'     => $order['total'],
	)
);
