<?php
/** Admin: approve or reject customer reviews before they appear on the homepage. */

require __DIR__ . '/_inc.php';
bv_require_admin();

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	bv_check_csrf();
	$id     = (int) ( $_POST['id'] ?? 0 );
	$action = (string) ( $_POST['action'] ?? '' );
	if ( 'approve' === $action ) {
		bv_update( 'reviews', array( 'status' => 'approved', 'approved_at' => bv_now() ), 'id = ?', array( $id ) );
		bv_flash( 'Review approved. It now shows on the homepage.' );
	} elseif ( 'reject' === $action ) {
		bv_update( 'reviews', array( 'status' => 'rejected' ), 'id = ?', array( $id ) );
		bv_flash( 'Review hidden.' );
	}
	bv_redirect( 'reviews.php?status=' . rawurlencode( (string) ( $_POST['back'] ?? 'pending' ) ) );
}

$status = in_array( $_GET['status'] ?? 'pending', array( 'pending', 'approved', 'rejected' ), true ) ? ( $_GET['status'] ?? 'pending' ) : 'pending';
$rows   = bv_rows( 'SELECT * FROM reviews WHERE status = ? ORDER BY created_at DESC LIMIT 200', array( $status ) );
$counts = array();
foreach ( bv_rows( 'SELECT status, COUNT(*) AS n FROM reviews GROUP BY status' ) as $r ) {
	$counts[ $r['status'] ] = (int) $r['n'];
}
bv_admin_header( 'Reviews', 'reviews.php' );
?>
<div class="page-head"><h1>Reviews</h1>
<nav class="seg" aria-label="Review status">
<?php foreach ( array( 'pending' => 'Waiting', 'approved' => 'On the site', 'rejected' => 'Hidden' ) as $k => $label ) : ?>
	<a href="?status=<?php echo $k; ?>"<?php echo $k === $status ? ' aria-current="true"' : ''; ?>><?php echo $label; ?> (<?php echo $counts[ $k ] ?? 0; ?>)</a>
<?php endforeach; ?>
</nav></div>
<p class="muted">Every review comes from a customer with a paid order. Publish genuine reviews as they are, good or bad: hiding or editing them to mislead shoppers breaks the Consumer Protection Act.</p>
<?php if ( ! $rows ) : ?><section class="card"><p class="muted">Nothing here.</p></section><?php endif; ?>
<?php foreach ( $rows as $r ) : ?>
<section class="card review">
	<p class="stars" aria-label="<?php echo (int) $r['rating']; ?> out of 5"><?php echo str_repeat( '&#9733;', (int) $r['rating'] ) . '<span class="off">' . str_repeat( '&#9733;', 5 - (int) $r['rating'] ) . '</span>'; ?></p>
	<blockquote><?php echo nl2br( h( $r['text'] ) ); ?></blockquote>
	<p class="small"><strong><?php echo h( $r['name'] ); ?></strong><?php echo $r['location'] ? ', ' . h( $r['location'] ) : ''; ?>
	&middot; Professionalism <?php echo (int) $r['professionalism'] ?: '-'; ?>/5 &middot; Convenience <?php echo (int) $r['convenience'] ?: '-'; ?>/5
	&middot; <a href="order.php?id=<?php echo h( $r['order_id'] ); ?>"><?php echo h( $r['order_id'] ); ?></a> (<?php echo h( (string) $r['products'] ); ?>)
	&middot; <?php echo h( bv_local( $r['created_at'] ) ); ?></p>
	<form method="post" class="btn-row">
		<?php echo bv_csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>"><input type="hidden" name="back" value="<?php echo h( $status ); ?>">
		<?php if ( 'approved' !== $status ) : ?><button class="btn" name="action" value="approve">Publish</button><?php endif; ?>
		<?php if ( 'rejected' !== $status ) : ?><button class="btn btn-ghost" name="action" value="reject">Hide</button><?php endif; ?>
	</form>
</section>
<?php endforeach; ?>
<?php
bv_admin_footer();
