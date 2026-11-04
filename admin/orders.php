<?php
/** Admin: all orders, with status filter, search and CSV export. */

require __DIR__ . '/_inc.php';
bv_require_admin();

$status = (string) ( $_GET['status'] ?? '' );
$q      = trim( (string) ( $_GET['q'] ?? '' ) );
$page   = max( 1, (int) ( $_GET['page'] ?? 1 ) );
$per    = 50;

$where  = array( "status <> 'checkout_failed'" );
$params = array();
if ( 'all' === $status ) {
	$where = array( '1 = 1' );
} elseif ( isset( BV_STATUSES[ $status ] ) ) {
	$where  = array( 'status = ?' );
	$params = array( $status );
}
if ( '' !== $q ) {
	$like     = '%' . strtolower( $q ) . '%';
	$where[]  = '(LOWER(id) LIKE ? OR LOWER(email) LIKE ? OR LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR phone LIKE ? OR LOWER(tracking_number) LIKE ?)';
	$params   = array_merge( $params, array( $like, $like, $like, $like, '%' . preg_replace( '/\D/', '', $q ) . '%', $like ) );
}
$w     = implode( ' AND ', $where );
$total = (int) bv_row( "SELECT COUNT(*) AS n FROM orders WHERE $w", $params )['n'];
$rows  = bv_rows( "SELECT id, first_name, last_name, email, total, status, created_at, paid_at, tracking_number FROM orders WHERE $w ORDER BY created_at DESC LIMIT $per OFFSET " . ( ( $page - 1 ) * $per ), $params );

$counts = array();
foreach ( bv_rows( 'SELECT status, COUNT(*) AS n FROM orders GROUP BY status' ) as $r ) {
	$counts[ $r['status'] ] = (int) $r['n'];
}

bv_admin_header( 'Orders', 'orders.php' );
$qs = function ( array $over ) use ( $status, $q ) {
	return '?' . http_build_query( array_filter( array_merge( array( 'status' => $status, 'q' => $q ), $over ), 'strlen' ) );
};
?>
<div class="page-head">
	<h1>Orders</h1>
	<a class="btn btn-ghost" href="export.php<?php echo h( $qs( array() ) ); ?>">Download CSV</a>
</div>

<form class="filters" method="get">
	<label class="sr">Search</label>
	<input type="search" name="q" value="<?php echo h( $q ); ?>" placeholder="Order number, name, email, phone or tracking number">
	<select name="status" aria-label="Status">
		<option value="">Open and completed</option>
		<?php foreach ( BV_STATUSES as $k => $label ) : ?>
			<option value="<?php echo h( $k ); ?>"<?php echo $k === $status ? ' selected' : ''; ?>><?php echo h( $label ); ?> (<?php echo $counts[ $k ] ?? 0; ?>)</option>
		<?php endforeach; ?>
		<option value="all"<?php echo 'all' === $status ? ' selected' : ''; ?>>Everything</option>
	</select>
	<button class="btn">Filter</button>
</form>

<section class="card">
	<?php if ( ! $rows ) : ?>
		<p class="muted">No orders found.</p>
	<?php else : ?>
	<table class="list"><thead><tr><th>Order</th><th>Customer</th><th>Placed</th><th>Status</th><th>Tracking</th><th class="r">Total</th></tr></thead><tbody>
	<?php foreach ( $rows as $o ) : ?>
		<tr>
			<td><a href="order.php?id=<?php echo h( $o['id'] ); ?>"><?php echo h( $o['id'] ); ?></a></td>
			<td><?php echo h( $o['first_name'] . ' ' . $o['last_name'] ); ?><br><span class="muted small"><?php echo h( $o['email'] ); ?></span></td>
			<td><?php echo h( bv_local( $o['created_at'] ) ); ?></td>
			<td><?php echo bv_status_badge( $o['status'] ); ?></td>
			<td class="small"><?php echo h( (string) $o['tracking_number'] ); ?></td>
			<td class="r"><?php echo bv_money( $o['total'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody></table>
	<?php if ( $total > $per ) : ?>
		<p class="pager"><?php if ( $page > 1 ) : ?><a href="<?php echo h( $qs( array( 'page' => $page - 1 ) ) ); ?>">&larr; Newer</a><?php endif; ?>
		<span>Page <?php echo $page; ?> of <?php echo (int) ceil( $total / $per ); ?></span>
		<?php if ( $page * $per < $total ) : ?><a href="<?php echo h( $qs( array( 'page' => $page + 1 ) ) ); ?>">Older &rarr;</a><?php endif; ?></p>
	<?php endif; ?>
	<?php endif; ?>
</section>
<?php
bv_admin_footer();
