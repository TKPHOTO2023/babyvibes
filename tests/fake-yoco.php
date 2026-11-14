<?php
/**
 * Local testing only: stands in for Yoco's hosted payment page. "Pay" sends a signed payment.succeeded
 * webhook and returns to the shop like Yoco does; "Decline" sends payment.failed; "Cancel" goes back.
 * It does nothing unless the test kit (mock-yoco.php) is loaded, so it is inert on a live site.
 */
defined( 'BV_YOCO_MOCK' ) || exit( 'Test file.' );
require __DIR__ . '/webhook-sender.php';

$checkout = preg_replace( '/[^\w]/', '', $_GET['checkout'] ?? '' );
$amount   = (int) ( $_GET['amount'] ?? 0 );
$success  = (string) ( $_GET['success'] ?? '/' );
$cancel   = (string) ( $_GET['cancel'] ?? '/' );
if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	$what = $_POST['what'] ?? '';
	if ( 'pay' === $what ) {
		bv_test_send_webhook( 'payment.succeeded', $checkout, $amount );
		header( 'Location: ' . $success );
	} elseif ( 'decline' === $what ) {
		bv_test_send_webhook( 'payment.failed', $checkout, $amount );
		header( 'Location: ' . str_replace( 'cancelled=1', 'failed=1', $cancel ) );
	} else {
		header( 'Location: ' . $cancel );
	}
	exit;
}
?><!doctype html><meta charset="utf-8"><title>Fake Yoco</title>
<h1>Fake Yoco payment page (test)</h1><p>Amount: R<?php echo number_format( $amount / 100, 2 ); ?></p>
<form method="post"><button id="fake-pay" name="what" value="pay">Pay</button> <button id="fake-decline" name="what" value="decline">Decline card</button> <button id="fake-cancel" name="what" value="cancel">Cancel</button></form>
