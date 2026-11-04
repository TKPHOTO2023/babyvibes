<?php
/** Admin: one order, its history, and the actions that move it along (each sends the right email). */

require __DIR__ . '/_inc.php';
$admin = bv_require_admin();

$id    = (string) ( $_GET['id'] ?? '' );
$order = bv_load_order( $id );
if ( ! $order ) {
	http_response_code( 404 );
	bv_admin_header( 'Order not found', 'orders.php' );
	echo '<h1>Order not found</h1><p><a href="orders.php">Back to orders</a></p>';
	bv_admin_footer();
	exit;
}

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	bv_check_csrf();
	$action = (string) ( $_POST['action'] ?? '' );
	$by     = $admin['name'] ? $admin['name'] : $admin['email'];
	$email  = ! empty( $_POST['notify'] );
	$text   = trim( mb_substr( (string) ( $_POST['text'] ?? '' ), 0, 1000 ) );

	switch ( $action ) {
		case 'ship':
			$courier = trim( mb_substr( (string) ( $_POST['courier'] ?? '' ), 0, 60 ) );
			$number  = trim( mb_substr( (string) ( $_POST['tracking_number'] ?? '' ), 0, 80 ) );
			$url     = trim( mb_substr( (string) ( $_POST['tracking_url'] ?? '' ), 0, 255 ) );
			if ( '' === $number ) {
				bv_flash( 'Add the tracking number first.', 'error' );
				break;
			}
			if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
				bv_flash( 'The tracking link must start with https://', 'error' );
				break;
			}
			bv_update_order( $id, array( 'status' => 'shipped', 'shipped_at' => bv_now(), 'courier' => $courier, 'tracking_number' => $number, 'tracking_url' => $url ) );
			bv_order_event( $id, 'shipped', 'Shipped with ' . ( $courier ?: 'the courier' ) . ". Tracking number: $number." );
			$sent = $email && bv_email_shipped( bv_load_order( $id ) );
			bv_flash( 'Marked as shipped.' . ( $email ? ( $sent ? ' The customer was emailed the tracking details.' : ' The email could not be sent: see Emails.' ) : '' ) );
			break;

		case 'deliver':
			bv_update_order( $id, array( 'status' => 'delivered', 'delivered_at' => bv_now() ) );
			bv_order_event( $id, 'delivered', 'Delivered. Enjoy!' );
			$sent = $email && bv_email_delivered( bv_load_order( $id ) );
			bv_flash( 'Marked as delivered.' . ( $email ? ( $sent ? ' The customer was asked for a review.' : ' The email could not be sent: see Emails.' ) : '' ) );
			break;

		case 'cancel':
		case 'refund':
			$refund = 'refund' === $action;
			bv_update_order( $id, array( 'status' => $refund ? 'refunded' : 'cancelled', 'cancelled_at' => bv_now() ) );
			bv_order_event( $id, $action, ( $refund ? 'Refunded.' : 'Cancelled.' ) . ( $text ? ' ' . $text : '' ), true );
			if ( $text ) {
				bv_order_event( $id, 'note', "Reason ($by): $text", false );
			}
			$sent = $email && bv_email_cancelled( bv_load_order( $id ), $refund, $text );
			bv_flash( ( $refund ? 'Marked as refunded. Remember to make the refund in the Yoco portal.' : 'Order cancelled.' ) . ( $email ? ( $sent ? ' The customer was emailed.' : ' The email could not be sent: see Emails.' ) : '' ) );
			break;

		case 'mark_paid':
			// For payments checked by hand in the Yoco portal (for example after an amount mismatch).
			bv_update_order( $id, array( 'status' => 'paid', 'paid_at' => $order['paid'] ?: bv_now() ) );
			bv_order_event( $id, 'paid', 'Payment received. Your order is being packed.' );
			bv_order_event( $id, 'note', "Marked as paid by hand ($by)." . ( $text ? " $text" : '' ), false );
			$sent = $email && bv_email_order_confirmation( bv_load_order( $id ) );
			bv_flash( 'Marked as paid.' . ( $email ? ( $sent ? ' The confirmation email was sent.' : ' The email could not be sent: see Emails.' ) : '' ) );
			break;

		case 'note':
			if ( '' !== $text ) {
				bv_order_event( $id, 'note', "$by: $text", false );
				bv_flash( 'Note added.' );
			}
			break;

		case 'resend':
			$sent = bv_email_order_confirmation( $order );
			bv_flash( $sent ? 'Order confirmation sent again.' : 'The email could not be sent: see Emails.', $sent ? 'ok' : 'error' );
			break;
	}
	bv_redirect( 'order.php?id=' . rawurlencode( $id ) );
}

$c      = $order['customer'];
$events = bv_order_events( $id );
$emails = bv_rows( 'SELECT template, subject, status, error, created_at FROM emails WHERE order_id = ? ORDER BY id DESC', array( $id ) );
$review = bv_row( 'SELECT rating, status FROM reviews WHERE order_id = ?', array( $id ) );
$prior  = $order['customer_id'] ? (int) bv_row( "SELECT COUNT(*) AS n FROM orders WHERE customer_id = ? AND status IN ('paid','shipped','delivered')", array( $order['customer_id'] ) )['n'] : 0;
$s      = $order['status'];

bv_admin_header( 'Order ' . $id, 'orders.php' );
?>
<p class="crumb"><a href="orders.php">&larr; Orders</a></p>
<div class="page-head">
	<h1>Order <?php echo h( $id ); ?> <?php echo bv_status_badge( $s ); ?></h1>
	<span class="muted">Placed <?php echo h( bv_local( $order['created'] ) ); ?></span>
</div>

<div class="cols cols-wide">
<div>
	<section class="card">
		<h2>Items</h2>
		<table><thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Total</th></tr></thead><tbody>
		<?php foreach ( $order['lines'] as $l ) : ?>
			<tr><td><?php echo h( $l['name'] ); ?></td><td class="r"><?php echo (int) $l['qty']; ?></td><td class="r"><?php echo bv_money( $l['unit'] ); ?></td><td class="r"><?php echo bv_money( $l['total'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody><tfoot>
			<tr><td colspan="3">Subtotal</td><td class="r"><?php echo bv_money( $order['subtotal'] ); ?></td></tr>
			<tr><td colspan="3">Delivery</td><td class="r"><?php echo $order['delivery'] ? bv_money( $order['delivery'] ) : 'Free'; ?></td></tr>
			<tr class="grand"><td colspan="3">Total</td><td class="r"><?php echo bv_money( $order['total'] ); ?></td></tr>
		</tfoot></table>
		<?php if ( $order['payment']['id'] ) : ?>
			<p class="muted small">Yoco payment <?php echo h( $order['payment']['id'] ); ?> &middot; <?php echo bv_money( $order['payment']['amount'] ); ?><?php echo $order['payment']['mode'] ? ' &middot; ' . h( $order['payment']['mode'] ) . ' mode' : ''; ?><?php echo $order['paid'] ? ' &middot; paid ' . h( bv_local( $order['paid'] ) ) : ''; ?></p>
		<?php endif; ?>
	</section>

	<section class="card">
		<h2>What to do next</h2>
		<?php if ( 'paid' === $s ) : ?>
			<form method="post" class="stack">
				<?php echo bv_csrf_field(); ?><input type="hidden" name="action" value="ship">
				<p>Packed and handed to the courier? Add the tracking details.</p>
				<div class="row3">
					<label>Courier<select name="courier"><?php foreach ( BV_COURIERS as $co ) : ?><option><?php echo h( $co ); ?></option><?php endforeach; ?></select></label>
					<label>Tracking number<input name="tracking_number" required></label>
					<label>Tracking link <span class="muted">(optional)</span><input name="tracking_url" type="url" placeholder="https://..."></label>
				</div>
				<label class="check"><input type="checkbox" name="notify" value="1" checked> Email the customer the tracking details</label>
				<button class="btn">Mark as shipped</button>
			</form>
		<?php elseif ( 'shipped' === $s ) : ?>
			<form method="post" class="stack">
				<?php echo bv_csrf_field(); ?><input type="hidden" name="action" value="deliver">
				<p>Shipped with <?php echo h( $order['tracking']['courier'] ); ?>, tracking number <strong><?php echo h( $order['tracking']['number'] ); ?></strong><?php echo $order['tracking']['url'] ? ' (<a href="' . h( $order['tracking']['url'] ) . '" target="_blank" rel="noopener">track</a>)' : ''; ?>.</p>
				<label class="check"><input type="checkbox" name="notify" value="1" checked> Email the customer and ask for a review</label>
				<button class="btn">Mark as delivered</button>
			</form>
		<?php elseif ( 'amount_mismatch' === $s || 'pending' === $s ) : ?>
			<p><?php echo 'pending' === $s ? 'This order has not been paid. If you confirmed the payment in the Yoco portal yourself, mark it as paid.' : 'Yoco reported a different amount from the order total. Check the payment in the Yoco portal first.'; ?></p>
			<form method="post" class="stack">
				<?php echo bv_csrf_field(); ?><input type="hidden" name="action" value="mark_paid">
				<label>Note <span class="muted">(optional)</span><input name="text" placeholder="e.g. Checked payment in Yoco"></label>
				<label class="check"><input type="checkbox" name="notify" value="1" checked> Send the customer the order confirmation</label>
				<button class="btn btn-ghost">Mark as paid</button>
			</form>
		<?php else : ?>
			<p class="muted">Nothing to do: this order is <?php echo h( strtolower( BV_STATUSES[ $s ] ?? $s ) ); ?>.</p>
		<?php endif; ?>

		<?php if ( in_array( $s, array( 'pending', 'paid', 'shipped', 'delivered', 'amount_mismatch' ), true ) ) : ?>
		<details class="danger"><summary>Cancel or refund</summary>
			<form method="post" class="stack" data-confirm="Are you sure? This changes the order and can email the customer.">
				<?php echo bv_csrf_field(); ?>
				<label>Reason, shown to the customer <span class="muted">(optional)</span><textarea name="text" rows="2"></textarea></label>
				<label class="check"><input type="checkbox" name="notify" value="1" checked> Email the customer</label>
				<p class="muted small">A refund here only records it. Make the actual refund in the Yoco portal.</p>
				<div class="btn-row">
					<button class="btn btn-ghost" name="action" value="cancel">Cancel order</button>
					<?php if ( 'pending' !== $s ) : ?><button class="btn btn-danger" name="action" value="refund">Mark as refunded</button><?php endif; ?>
				</div>
			</form>
		</details>
		<?php endif; ?>
	</section>

	<section class="card">
		<h2>History</h2>
		<ol class="timeline">
		<?php foreach ( $events as $e ) : ?>
			<li class="ev-<?php echo h( $e['type'] ); ?>"><time><?php echo h( bv_local( $e['created_at'] ) ); ?></time> <?php echo h( $e['message'] ); ?><?php echo in_array( $e['type'], array( 'note', 'checkout_failed', 'amount_mismatch' ), true ) ? ' <span class="muted small">(internal)</span>' : ''; ?></li>
		<?php endforeach; ?>
		</ol>
		<form method="post" class="inline">
			<?php echo bv_csrf_field(); ?><input type="hidden" name="action" value="note">
			<input name="text" placeholder="Add an internal note (the customer doesn't see it)" aria-label="Internal note" required>
			<button class="btn btn-ghost">Add note</button>
		</form>
	</section>
</div>

<div>
	<section class="card">
		<h2>Customer</h2>
		<p><strong><?php echo h( $c['firstName'] . ' ' . $c['lastName'] ); ?></strong><br>
		<a href="mailto:<?php echo h( $c['email'] ); ?>"><?php echo h( $c['email'] ); ?></a><br>
		<a href="tel:<?php echo h( $c['phone'] ); ?>"><?php echo h( $c['phone'] ); ?></a>
		&middot; <a href="https://wa.me/27<?php echo h( substr( $c['phone'], 1 ) ); ?>" target="_blank" rel="noopener">WhatsApp</a></p>
		<p class="muted small"><?php echo $order['customer_id'] ? 'Has an account &middot; ' . $prior . ' paid ' . ( 1 === $prior ? 'order' : 'orders' ) : 'Guest checkout'; ?> &middot; <a href="orders.php?q=<?php echo h( rawurlencode( $c['email'] ) ); ?>">all their orders</a></p>
	</section>
	<section class="card">
		<h2>Deliver to</h2>
		<p><?php echo nl2br( h( $c['firstName'] . ' ' . $c['lastName'] . "\n" . bv_address( $c ) ) ); ?></p>
		<?php if ( $c['notes'] ) : ?><p class="note"><strong>Delivery notes:</strong><br><?php echo nl2br( h( $c['notes'] ) ); ?></p><?php endif; ?>
		<button class="btn btn-ghost btn-small" type="button" data-copy="<?php echo h( $c['firstName'] . ' ' . $c['lastName'] . "\n" . $c['phone'] . "\n" . bv_address( $c ) ); ?>">Copy address</button>
	</section>
	<section class="card">
		<h2>Emails</h2>
		<?php if ( ! $emails ) : ?><p class="muted">None yet.</p><?php else : ?>
		<ul class="plain small">
		<?php foreach ( $emails as $e ) : ?>
			<li><?php echo 'sent' === $e['status'] ? '&#10003;' : '<span class="err">Failed</span>'; ?> <?php echo h( $e['subject'] ); ?><br><span class="muted"><?php echo h( bv_local( $e['created_at'] ) ); ?></span></li>
		<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<?php if ( in_array( $s, array( 'paid', 'shipped', 'delivered' ), true ) ) : ?>
		<form method="post"><?php echo bv_csrf_field(); ?><input type="hidden" name="action" value="resend"><button class="btn btn-ghost btn-small">Resend order confirmation</button></form>
		<?php endif; ?>
	</section>
	<?php if ( $review ) : ?>
	<section class="card"><h2>Review</h2><p><?php echo str_repeat( '&#9733;', (int) $review['rating'] ); ?> (<?php echo h( $review['status'] ); ?>) &middot; <a href="reviews.php?status=<?php echo h( $review['status'] ); ?>">open</a></p></section>
	<?php endif; ?>
</div>
</div>
<?php
bv_admin_footer();
