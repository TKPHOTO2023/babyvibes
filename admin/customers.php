<?php
/** Admin: customer accounts, with what each has spent. */

require __DIR__ . '/_inc.php';
bv_require_admin();

$q      = trim( (string) ( $_GET['q'] ?? '' ) );
$where  = '1 = 1';
$params = array();
if ( '' !== $q ) {
	$like   = '%' . strtolower( $q ) . '%';
	$where  = '(LOWER(c.email) LIKE ? OR LOWER(c.first_name) LIKE ? OR LOWER(c.last_name) LIKE ? OR c.phone LIKE ?)';
	$params = array( $like, $like, $like, $like );
}
$rows = bv_rows(
	"SELECT c.id, c.email, c.first_name, c.last_name, c.phone, c.city, c.marketing_opt_in, c.created_at, c.last_login_at,
		COUNT(o.id) AS orders, COALESCE(SUM(o.total), 0) AS spent, MAX(o.paid_at) AS last_order
	FROM customers c LEFT JOIN orders o ON o.customer_id = c.id AND o.status IN ('paid', 'shipped', 'delivered')
	WHERE $where GROUP BY c.id, c.email, c.first_name, c.last_name, c.phone, c.city, c.marketing_opt_in, c.created_at, c.last_login_at
	ORDER BY c.created_at DESC LIMIT 500",
	$params
);
$total  = (int) bv_row( 'SELECT COUNT(*) AS n FROM customers' )['n'];
$optins = (int) bv_row( 'SELECT COUNT(*) AS n FROM customers WHERE marketing_opt_in = 1' )['n'];
$guests = (int) bv_row( "SELECT COUNT(DISTINCT email) AS n FROM orders WHERE customer_id IS NULL AND status IN ('paid','shipped','delivered')" )['n'];

bv_admin_header( 'Customers', 'customers.php' );
?>
<div class="page-head"><h1>Customers</h1>
<span class="muted"><?php echo $total; ?> accounts &middot; <?php echo $optins; ?> said yes to marketing emails &middot; <?php echo $guests; ?> guest buyers</span></div>
<form class="filters" method="get">
	<input type="search" name="q" value="<?php echo h( $q ); ?>" placeholder="Name, email or phone" aria-label="Search customers">
	<button class="btn">Search</button>
</form>
<section class="card">
<?php if ( ! $rows ) : ?><p class="muted">No customer accounts yet. Guests who check out without an account are under Orders.</p><?php else : ?>
<table class="list"><thead><tr><th>Customer</th><th>Phone</th><th>Town</th><th>Joined</th><th class="r">Orders</th><th class="r">Spent</th><th>Marketing</th></tr></thead><tbody>
<?php foreach ( $rows as $c ) : ?>
	<tr>
		<td><strong><?php echo h( $c['first_name'] . ' ' . $c['last_name'] ); ?></strong><br><a class="small" href="mailto:<?php echo h( $c['email'] ); ?>"><?php echo h( $c['email'] ); ?></a></td>
		<td><?php echo h( $c['phone'] ); ?></td>
		<td><?php echo h( $c['city'] ); ?></td>
		<td><?php echo h( bv_local( $c['created_at'], 'j M Y' ) ); ?></td>
		<td class="r"><?php echo (int) $c['orders'] ? '<a href="orders.php?q=' . h( rawurlencode( $c['email'] ) ) . '">' . (int) $c['orders'] . '</a>' : '0'; ?></td>
		<td class="r"><?php echo bv_money( $c['spent'] ); ?></td>
		<td><?php echo $c['marketing_opt_in'] ? 'Yes' : 'No'; ?></td>
	</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</section>
<?php
bv_admin_footer();
