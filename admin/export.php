<?php
/** Admin: download orders as a CSV file (opens in Excel or Google Sheets). Uses the same filters as the orders list. */

require __DIR__ . '/_inc.php';
bv_require_admin();

$status = (string) ( $_GET['status'] ?? '' );
$where  = "status <> 'checkout_failed'";
$params = array();
if ( 'all' === $status ) {
	$where = '1 = 1';
} elseif ( isset( BV_STATUSES[ $status ] ) ) {
	$where  = 'status = ?';
	$params = array( $status );
}
$q = trim( (string) ( $_GET['q'] ?? '' ) );
if ( '' !== $q ) {
	$like    = '%' . strtolower( $q ) . '%';
	$where  .= ' AND (LOWER(id) LIKE ? OR LOWER(email) LIKE ? OR LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ?)';
	$params  = array_merge( $params, array( $like, $like, $like, $like ) );
}

header( 'Content-Type: text/csv; charset=utf-8' );
header( 'Content-Disposition: attachment; filename="babyvibes-orders-' . bv_local( bv_now(), 'Y-m-d' ) . '.csv"' );
$out = fopen( 'php://output', 'w' );
fwrite( $out, "\xEF\xBB\xBF" ); // So Excel reads the accents and the rand sign correctly.
fputcsv( $out, array( 'Order', 'Status', 'Placed', 'Paid', 'First name', 'Last name', 'Email', 'Phone', 'Address', 'Suburb', 'City', 'Province', 'Postal code', 'Items', 'Subtotal (R)', 'Delivery (R)', 'Total (R)', 'Courier', 'Tracking number', 'Account' ) );
$st = bv_q( "SELECT * FROM orders WHERE $where ORDER BY created_at DESC", $params );
while ( $o = $st->fetch() ) {
	$items = array();
	foreach ( bv_rows( 'SELECT qty, name FROM order_items WHERE order_id = ?', array( $o['id'] ) ) as $i ) {
		$items[] = $i['qty'] . ' x ' . $i['name'];
	}
	$row = array(
		$o['id'], BV_STATUSES[ $o['status'] ] ?? $o['status'], bv_local( $o['created_at'], 'Y-m-d H:i' ), bv_local( $o['paid_at'], 'Y-m-d H:i' ),
		$o['first_name'], $o['last_name'], $o['email'], $o['phone'], trim( $o['address1'] . ' ' . $o['address2'] ), $o['suburb'], $o['city'], $o['province'], $o['postcode'],
		implode( '; ', $items ), number_format( $o['subtotal'] / 100, 2, '.', '' ), number_format( $o['delivery'] / 100, 2, '.', '' ), number_format( $o['total'] / 100, 2, '.', '' ),
		$o['courier'], $o['tracking_number'], $o['customer_id'] ? 'Yes' : 'Guest',
	);
	// Stop spreadsheet formulas sneaking in through customer-typed fields.
	$row = array_map( function ( $v ) { $v = (string) $v; return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v; }, $row );
	fputcsv( $out, $row );
}
fclose( $out );
