<?php
/** Admin dashboard: sales, orders, best sellers and what needs doing. */

require __DIR__ . '/_inc.php';
bv_require_admin();

$days  = in_array( (int) ( $_GET['r'] ?? 30 ), array( 7, 30, 90, 365 ), true ) ? (int) ( $_GET['r'] ?? 30 ) : 30;
$tz    = new DateTimeZone( 'Africa/Johannesburg' );
$start = new DateTime( 'today', $tz );
$start->modify( '-' . ( $days - 1 ) . ' days' );
$since = ( clone $start )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$prev  = ( clone $start )->modify( "-$days days" )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$sale  = "'" . implode( "','", BV_SALE_STATUSES ) . "'";

$orders      = bv_rows( "SELECT id, email, total, paid_at FROM orders WHERE status IN ($sale) AND paid_at >= ?", array( $since ) );
$prev_orders = bv_rows( "SELECT total FROM orders WHERE status IN ($sale) AND paid_at >= ? AND paid_at < ?", array( $prev, $since ) );

$revenue      = array_sum( array_column( $orders, 'total' ) );
$prev_revenue = array_sum( array_column( $prev_orders, 'total' ) );
$count        = count( $orders );
$prev_count   = count( $prev_orders );
$aov          = $count ? (int) round( $revenue / $count ) : 0;
$prev_aov     = $prev_count ? (int) round( $prev_revenue / $prev_count ) : 0;

$new_customers  = (int) bv_row( 'SELECT COUNT(*) AS n FROM customers WHERE created_at >= ?', array( $since ) )['n'];
$prev_customers = (int) bv_row( 'SELECT COUNT(*) AS n FROM customers WHERE created_at >= ? AND created_at < ?', array( $prev, $since ) )['n'];
$abandoned      = (int) bv_row( "SELECT COUNT(*) AS n FROM orders WHERE status IN ('pending', 'checkout_failed') AND created_at >= ? AND created_at < ?", array( $since, gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) )['n'];
$to_ship        = (int) bv_row( "SELECT COUNT(*) AS n FROM orders WHERE status = 'paid'" )['n'];
$to_check       = (int) bv_row( "SELECT COUNT(*) AS n FROM orders WHERE status = 'amount_mismatch'" )['n'];
$reviews_wait   = (int) bv_row( "SELECT COUNT(*) AS n FROM reviews WHERE status = 'pending'" )['n'];
$failed_emails  = (int) bv_row( "SELECT COUNT(*) AS n FROM emails WHERE status = 'failed' AND created_at >= ?", array( gmdate( 'Y-m-d H:i:s', time() - 7 * 86400 ) ) )['n'];

// Returning customers: people in this period who had paid before it.
$emails    = array_unique( array_column( $orders, 'email' ) );
$returning = 0;
foreach ( $emails as $e ) {
	if ( bv_row( "SELECT id FROM orders WHERE email = ? AND status IN ($sale) AND paid_at < ? LIMIT 1", array( $e, $since ) ) ) {
		$returning++;
	}
}

// Items and best sellers.
$items    = bv_rows( "SELECT i.slug, i.qty, i.total FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.status IN ($sale) AND o.paid_at >= ?", array( $since ) );
$products = bv_products();
$cats     = array();
foreach ( bv_read_json( 'data/products.json' )['categories'] as $c ) {
	$cats[ $c['slug'] ] = $c['name'];
}
$best   = array();
$by_cat = array();
$units  = 0;
foreach ( $items as $i ) {
	$s = $i['slug'];
	if ( ! isset( $best[ $s ] ) ) {
		$best[ $s ] = array( 'name' => isset( $products[ $s ] ) ? $products[ $s ]['name'] : $s, 'qty' => 0, 'total' => 0 );
	}
	$best[ $s ]['qty']   += $i['qty'];
	$best[ $s ]['total'] += $i['total'];
	$cat                  = isset( $products[ $s ] ) && isset( $cats[ $products[ $s ]['category'] ] ) ? $cats[ $products[ $s ]['category'] ] : 'Other';
	$by_cat[ $cat ]       = ( $by_cat[ $cat ] ?? 0 ) + $i['total'];
	$units               += $i['qty'];
}
uasort( $best, function ( $a, $b ) { return $b['total'] <=> $a['total']; } );
arsort( $by_cat );

// Daily revenue in South African time.
$daily = array();
$d     = clone $start;
for ( $n = 0; $n < $days; $n++ ) {
	$daily[ $d->format( 'Y-m-d' ) ] = array( 'revenue' => 0, 'orders' => 0 );
	$d->modify( '+1 day' );
}
foreach ( $orders as $o ) {
	$k = bv_local( $o['paid_at'], 'Y-m-d' );
	if ( isset( $daily[ $k ] ) ) {
		$daily[ $k ]['revenue'] += $o['total'];
		$daily[ $k ]['orders']++;
	}
}

$recent = bv_rows( "SELECT id, first_name, last_name, total, status, created_at FROM orders WHERE status NOT IN ('checkout_failed') ORDER BY created_at DESC LIMIT 8" );

function bv_delta( $now, $before, $label ) {
	if ( ! $before ) {
		return '<span class="delta">' . ( $now ? 'New this period' : 'No change' ) . '</span>';
	}
	$pct = round( ( $now - $before ) / $before * 100 );
	$dir = $pct > 0 ? 'up' : ( $pct < 0 ? 'down' : 'flat' );
	$arr = array( 'up' => '&#9650;', 'down' => '&#9660;', 'flat' => '&#9644;' )[ $dir ];
	return '<span class="delta delta-' . $dir . '"><span aria-hidden="true">' . $arr . '</span> ' . abs( $pct ) . '% ' . ( 'down' === $dir ? 'lower' : 'higher' ) . ' than ' . h( $label ) . '</span>';
}

/** Single-series bar chart of daily revenue (inline SVG, hover tooltips from admin.js). */
function bv_revenue_chart( array $daily ) {
	$w    = 960;
	$hgt  = 240;
	$padl = 64;
	$padb = 28;
	$max  = max( 1, max( array_column( $daily, 'revenue' ) ) );
	// Round the top of the scale to a tidy number.
	$step = pow( 10, floor( log10( $max / 100 ) ) ) * 100;
	foreach ( array( 1, 2, 2.5, 5, 10 ) as $m ) {
		if ( $max <= $step * $m * 4 ) {
			$step *= $m;
			break;
		}
	}
	$top   = $step * 4;
	$n     = count( $daily );
	$slot  = ( $w - $padl ) / $n;
	$bar   = max( 2, $slot - 2 );
	$plotH = $hgt - $padb - 8;
	$svg   = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="Revenue per day">';
	for ( $g = 0; $g <= 4; $g++ ) {
		$y    = 8 + $plotH - $plotH * $g / 4;
		$svg .= '<line class="grid" x1="' . $padl . '" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '"/>'
			. '<text class="tick" x="' . ( $padl - 8 ) . '" y="' . ( $y + 4 ) . '" text-anchor="end">R' . number_format( $step * $g / 100, 0, '.', ' ' ) . '</text>';
	}
	$i     = 0;
	$every = $n <= 7 ? 1 : ( $n <= 31 ? 5 : ( $n <= 90 ? 14 : 60 ) );
	foreach ( $daily as $date => $v ) {
		$x  = $padl + $i * $slot + 1;
		$bh = $v['revenue'] ? max( 3, $plotH * $v['revenue'] / $top ) : 0;
		$y  = 8 + $plotH - $bh;
		$dt = DateTime::createFromFormat( 'Y-m-d', $date );
		$tip = $dt->format( 'D j M' ) . ': ' . bv_rand( $v['revenue'] ) . ' from ' . $v['orders'] . ( 1 === $v['orders'] ? ' order' : ' orders' );
		// Invisible full-height hit area, so thin or empty days are easy to hover.
		$svg .= '<g class="day" data-tip="' . h( $tip ) . '" tabindex="0"><rect class="hit" x="' . ( $x - 1 ) . '" y="8" width="' . $slot . '" height="' . $plotH . '"/>';
		if ( $bh ) {
			$r    = min( 4, $bar / 2, $bh );
			$svg .= '<path class="bar" d="M' . $x . ' ' . ( 8 + $plotH ) . 'V' . ( $y + $r ) . 'Q' . $x . ' ' . $y . ' ' . ( $x + $r ) . ' ' . $y . 'H' . ( $x + $bar - $r ) . 'Q' . ( $x + $bar ) . ' ' . $y . ' ' . ( $x + $bar ) . ' ' . ( $y + $r ) . 'V' . ( 8 + $plotH ) . 'Z"/>';
		}
		$svg .= '<title>' . h( $tip ) . '</title></g>';
		if ( 0 === $i % $every ) {
			$svg .= '<text class="tick" x="' . ( $x + $bar / 2 ) . '" y="' . ( $hgt - 8 ) . '" text-anchor="middle">' . $dt->format( 'j M' ) . '</text>';
		}
		$i++;
	}
	return $svg . '<line class="axis" x1="' . $padl . '" x2="' . $w . '" y1="' . ( 8 + $plotH ) . '" y2="' . ( 8 + $plotH ) . '"/></svg>';
}

$label = 'the ' . $days . ' days before';
bv_admin_header( 'Dashboard', 'index.php' );
?>
<div class="page-head">
	<h1>Dashboard</h1>
	<nav class="seg" aria-label="Period">
		<?php foreach ( array( 7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '12 months' ) as $r => $t ) : ?>
			<a href="?r=<?php echo $r; ?>"<?php echo $r === $days ? ' aria-current="true"' : ''; ?>><?php echo $t; ?></a>
		<?php endforeach; ?>
	</nav>
</div>

<?php if ( $to_ship || $to_check || $reviews_wait || $failed_emails ) : ?>
<ul class="todo">
	<?php if ( $to_ship ) : ?><li><a href="orders.php?status=paid"><b><?php echo $to_ship; ?></b> paid <?php echo 1 === $to_ship ? 'order' : 'orders'; ?> to pack and ship</a></li><?php endif; ?>
	<?php if ( $to_check ) : ?><li class="warn"><a href="orders.php?status=amount_mismatch"><b><?php echo $to_check; ?></b> <?php echo 1 === $to_check ? 'payment' : 'payments'; ?> with the wrong amount to check in Yoco</a></li><?php endif; ?>
	<?php if ( $reviews_wait ) : ?><li><a href="reviews.php"><b><?php echo $reviews_wait; ?></b> <?php echo 1 === $reviews_wait ? 'review' : 'reviews'; ?> waiting for approval</a></li><?php endif; ?>
	<?php if ( $failed_emails ) : ?><li class="warn"><a href="emails.php?status=failed"><b><?php echo $failed_emails; ?></b> <?php echo 1 === $failed_emails ? 'email' : 'emails'; ?> failed to send this week</a></li><?php endif; ?>
</ul>
<?php endif; ?>

<section class="kpis" aria-label="Key numbers for the last <?php echo $days; ?> days">
	<div class="kpi"><h2>Revenue</h2><p class="num"><?php echo bv_money( $revenue ); ?></p><?php echo bv_delta( $revenue, $prev_revenue, $label ); ?></div>
	<div class="kpi"><h2>Orders</h2><p class="num"><?php echo $count; ?></p><?php echo bv_delta( $count, $prev_count, $label ); ?></div>
	<div class="kpi"><h2>Average order</h2><p class="num"><?php echo bv_money( $aov ); ?></p><?php echo bv_delta( $aov, $prev_aov, $label ); ?></div>
	<div class="kpi"><h2>Items sold</h2><p class="num"><?php echo $units; ?></p><span class="delta"><?php echo count( $best ); ?> different products</span></div>
	<div class="kpi"><h2>New accounts</h2><p class="num"><?php echo $new_customers; ?></p><?php echo bv_delta( $new_customers, $prev_customers, $label ); ?></div>
	<div class="kpi"><h2>Returning buyers</h2><p class="num"><?php echo $returning; ?></p><span class="delta">of <?php echo count( $emails ); ?> <?php echo 1 === count( $emails ) ? 'buyer' : 'buyers'; ?></span></div>
	<div class="kpi"><h2>Abandoned checkouts</h2><p class="num"><?php echo $abandoned; ?></p><span class="delta">Went to payment but didn't pay</span></div>
</section>

<section class="card">
	<h2>Revenue per day</h2>
	<p class="muted">Paid orders, South African time. Hover a day for details.</p>
	<?php echo bv_revenue_chart( $daily ); ?>
	<details class="table-view"><summary>Show as a table</summary>
		<table><thead><tr><th>Day</th><th class="r">Orders</th><th class="r">Revenue</th></tr></thead><tbody>
		<?php foreach ( array_reverse( $daily, true ) as $date => $v ) : ?>
			<tr><td><?php echo h( DateTime::createFromFormat( 'Y-m-d', $date )->format( 'D j M Y' ) ); ?></td><td class="r"><?php echo $v['orders']; ?></td><td class="r"><?php echo bv_money( $v['revenue'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	</details>
</section>

<div class="cols">
	<section class="card">
		<h2>Best sellers</h2>
		<?php if ( ! $best ) : ?><p class="muted">No sales in this period yet.</p><?php else : ?>
		<table><thead><tr><th>Product</th><th class="r">Sold</th><th class="r">Revenue</th></tr></thead><tbody>
		<?php foreach ( array_slice( $best, 0, 10, true ) as $b ) : ?>
			<tr><td><?php echo h( $b['name'] ); ?></td><td class="r"><?php echo (int) $b['qty']; ?></td><td class="r"><?php echo bv_money( $b['total'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>
	</section>
	<section class="card">
		<h2>Sales by category</h2>
		<?php if ( ! $by_cat ) : ?><p class="muted">No sales in this period yet.</p><?php else : ?>
		<table class="bars"><tbody>
		<?php $top = max( $by_cat ); foreach ( $by_cat as $name => $total ) : ?>
			<tr><td><?php echo h( $name ); ?></td><td class="barcell"><span class="hbar" style="width:<?php echo max( 2, round( $total / $top * 100 ) ); ?>%"></span></td><td class="r"><?php echo bv_money( $total ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>
	</section>
</div>

<section class="card">
	<div class="card-head"><h2>Latest orders</h2><a href="orders.php">All orders</a></div>
	<?php if ( ! $recent ) : ?><p class="muted">No orders yet. They'll appear here as soon as someone checks out.</p><?php else : ?>
	<table><thead><tr><th>Order</th><th>Customer</th><th>Placed</th><th>Status</th><th class="r">Total</th></tr></thead><tbody>
	<?php foreach ( $recent as $o ) : ?>
		<tr><td><a href="order.php?id=<?php echo h( $o['id'] ); ?>"><?php echo h( $o['id'] ); ?></a></td><td><?php echo h( $o['first_name'] . ' ' . $o['last_name'] ); ?></td>
		<td><?php echo h( bv_local( $o['created_at'] ) ); ?></td><td><?php echo bv_status_badge( $o['status'] ); ?></td><td class="r"><?php echo bv_money( $o['total'] ); ?></td></tr>
	<?php endforeach; ?>
	</tbody></table>
	<?php endif; ?>
</section>
<?php
bv_admin_footer();
