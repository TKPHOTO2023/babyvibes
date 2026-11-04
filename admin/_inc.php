<?php
/**
 * Shared code for the BabyVibes admin: sign-in check, CSRF protection, layout and helpers.
 * Not a page: admin/.htaccess blocks direct requests to it.
 */

define( 'BV_HTML', true );
require __DIR__ . '/../api/lib.php';

header( 'X-Frame-Options: DENY' );
header( 'X-Content-Type-Options: nosniff' );
header( 'X-Robots-Tag: noindex, nofollow' );
header( 'Referrer-Policy: same-origin' );
header( 'Cache-Control: no-store' );

const BV_SALE_STATUSES = array( 'paid', 'shipped', 'delivered' );
const BV_COURIERS      = array( 'The Courier Guy', 'Pudo', 'PostNet', 'Aramex', 'Fastway', 'Paxi', 'DSV', 'Courier' );

function h( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

/** UTC database time shown in South African time. */
function bv_local( $utc, $format = 'j M Y, H:i' ) {
	if ( ! $utc ) {
		return '';
	}
	$d = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
	$d->setTimezone( new DateTimeZone( 'Africa/Johannesburg' ) );
	return $d->format( $format );
}

function bv_admin() {
	bv_session();
	if ( empty( $_SESSION['admin_id'] ) ) {
		return null;
	}
	return bv_row( 'SELECT id, email, name FROM admins WHERE id = ?', array( (int) $_SESSION['admin_id'] ) );
}

/** Every admin page starts with this: sends signed-out visitors to the login page. */
function bv_require_admin() {
	$a = bv_admin();
	if ( ! $a ) {
		header( 'Location: login.php?next=' . rawurlencode( $_SERVER['REQUEST_URI'] ?? '' ) );
		exit;
	}
	// Sign out after 8 hours without activity.
	if ( isset( $_SESSION['admin_seen'] ) && time() - $_SESSION['admin_seen'] > 8 * 3600 ) {
		unset( $_SESSION['admin_id'] );
		header( 'Location: login.php' );
		exit;
	}
	$_SESSION['admin_seen'] = time();
	return $a;
}

function bv_csrf_token() {
	bv_session();
	if ( empty( $_SESSION['csrf'] ) ) {
		$_SESSION['csrf'] = bin2hex( random_bytes( 32 ) );
	}
	return $_SESSION['csrf'];
}

function bv_csrf_field() {
	return '<input type="hidden" name="csrf" value="' . h( bv_csrf_token() ) . '">';
}

function bv_check_csrf() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! hash_equals( bv_csrf_token(), (string) ( $_POST['csrf'] ?? '' ) ) ) {
		http_response_code( 400 );
		exit( 'The form expired. Go back, refresh the page and try again.' );
	}
}

function bv_flash( $message = null, $kind = 'ok' ) {
	bv_session();
	if ( null !== $message ) {
		$_SESSION['flash'] = array( $kind, $message );
		return null;
	}
	$f = $_SESSION['flash'] ?? null;
	unset( $_SESSION['flash'] );
	return $f;
}

function bv_redirect( $url ) {
	header( 'Location: ' . $url );
	exit;
}

function bv_status_badge( $status ) {
	$label = BV_STATUSES[ $status ] ?? $status;
	return '<span class="st st-' . h( $status ) . '">' . h( $label ) . '</span>';
}

function bv_money( $cents ) {
	return h( bv_rand( (int) $cents ) );
}

function bv_admin_header( $title, $active = '' ) {
	$a    = bv_admin();
	$nav  = array(
		'index.php'     => 'Dashboard',
		'orders.php'    => 'Orders',
		'customers.php' => 'Customers',
		'reviews.php'   => 'Reviews',
		'emails.php'    => 'Emails',
	);
	$to_ship = (int) bv_row( "SELECT COUNT(*) AS n FROM orders WHERE status = 'paid'" )['n'];
	$pending = (int) bv_row( "SELECT COUNT(*) AS n FROM reviews WHERE status = 'pending'" )['n'];
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<title>' . h( $title ) . ' | BabyVibes admin</title><meta name="robots" content="noindex,nofollow">'
		. '<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="admin.css"></head><body>'
		. '<header class="top"><a class="brand" href="index.php"><img src="../assets/img/logo.png" alt="BabyVibes" height="34"><span>Admin</span></a>'
		. '<nav aria-label="Admin">';
	foreach ( $nav as $href => $label ) {
		$count = 'orders.php' === $href && $to_ship ? ' <b class="count" title="Paid orders waiting to ship">' . $to_ship . '</b>'
			: ( 'reviews.php' === $href && $pending ? ' <b class="count" title="Reviews waiting for approval">' . $pending . '</b>' : '' );
		echo '<a href="' . $href . '"' . ( $active === $href ? ' aria-current="page"' : '' ) . '>' . h( $label ) . $count . '</a>';
	}
	echo '</nav><div class="who"><a href="../" target="_blank" rel="noopener">View shop</a>';
	if ( $a ) {
		echo '<form method="post" action="logout.php">' . bv_csrf_field() . '<button class="linkish">Sign out</button></form>';
	}
	echo '</div></header><main class="wrap">';
	$f = bv_flash();
	if ( $f ) {
		echo '<p class="flash flash-' . h( $f[0] ) . '" role="status">' . h( $f[1] ) . '</p>';
	}
}

function bv_admin_footer() {
	echo '</main><script src="admin.js" defer></script></body></html>';
}
