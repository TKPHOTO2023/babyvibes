<?php
/** Admin: every automatic email the shop has sent, and any that failed. */

require __DIR__ . '/_inc.php';
bv_require_admin();

$status = 'failed' === ( $_GET['status'] ?? '' ) ? 'failed' : '';
$rows   = bv_rows( 'SELECT * FROM emails' . ( $status ? " WHERE status = 'failed'" : '' ) . ' ORDER BY id DESC LIMIT 300' );
$names  = array(
	'welcome'            => 'Welcome',
	'order_confirmation' => 'Order confirmation',
	'new_order_admin'    => 'New order (to you)',
	'order_shipped'      => 'Shipped',
	'order_delivered'    => 'Delivered + review request',
	'order_cancelled'    => 'Cancelled',
	'order_refunded'     => 'Refunded',
	'password_reset'     => 'Password reset',
	'review_admin'       => 'New review (to you)',
	'amount_mismatch'    => 'Payment check (to you)',
);
bv_admin_header( 'Emails', 'emails.php' );
?>
<div class="page-head"><h1>Emails</h1>
<nav class="seg"><a href="emails.php"<?php echo $status ? '' : ' aria-current="true"'; ?>>All</a><a href="?status=failed"<?php echo $status ? ' aria-current="true"' : ''; ?>>Failed</a></nav></div>
<p class="muted">Sent through Brevo. Delivery and opens are in your Brevo account under Transactional &gt; Logs.</p>
<section class="card">
<?php if ( ! $rows ) : ?><p class="muted">No emails yet.</p><?php else : ?>
<table class="list"><thead><tr><th>Sent</th><th>Email</th><th>To</th><th>Subject</th><th>Order</th><th>Result</th></tr></thead><tbody>
<?php foreach ( $rows as $e ) : ?>
	<tr><td><?php echo h( bv_local( $e['created_at'] ) ); ?></td><td><?php echo h( $names[ $e['template'] ] ?? $e['template'] ); ?></td><td><?php echo h( $e['to_email'] ); ?></td><td><?php echo h( $e['subject'] ); ?></td>
	<td><?php echo $e['order_id'] ? '<a href="order.php?id=' . h( $e['order_id'] ) . '">' . h( $e['order_id'] ) . '</a>' : ''; ?></td>
	<td><?php echo 'sent' === $e['status'] ? 'Sent' : '<span class="err">Failed</span><br><span class="small muted">' . h( mb_substr( (string) $e['error'], 0, 160 ) ) . '</span>'; ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</section>
<?php
bv_admin_footer();
