<?php
/**
 * Shared code for the BabyVibes back end: settings, database, products, orders, customers,
 * Yoco payments, sessions and security helpers. Emails live in emails.php.
 * Not a page: the .htaccess in this folder blocks direct requests to it.
 */

const BV_YOCO_API = 'https://payments.yoco.com/api';

/** Order statuses, in the order an order normally moves through them. */
const BV_STATUSES = array(
	'pending'         => 'Awaiting payment',
	'paid'            => 'Paid, being packed',
	'shipped'         => 'Shipped',
	'delivered'       => 'Delivered',
	'cancelled'       => 'Cancelled',
	'refunded'        => 'Refunded',
	'checkout_failed' => 'Payment page failed',
	'amount_mismatch' => 'Check payment amount',
);

function bv_root() {
	return dirname( __DIR__ );
}

function bv_config() {
	static $config = null;
	if ( null === $config ) {
		$file = defined( 'BV_CONFIG' ) ? BV_CONFIG : __DIR__ . '/config.php';
		if ( ! is_readable( $file ) ) {
			bv_fail( 'The shop is not set up yet (api/config.php is missing).', 500 );
		}
		$config = array_merge(
			array(
				'site_url'            => '',
				'db'                  => array(),
				'yoco_secret_key'     => '',
				'yoco_webhook_secret' => '',
				'brevo_api_key'       => '',
				'from_email'          => '',
				'from_name'           => 'BabyVibes',
				'orders_email'        => '',
				'admin_token'         => '',
			),
			require $file
		);
	}
	return $config;
}

function bv_site_url( $path = '' ) {
	return rtrim( bv_config()['site_url'], '/' ) . '/' . ltrim( $path, '/' );
}

/** Stops with an error: JSON for API calls, plain text otherwise. */
function bv_fail( $message, $status = 500 ) {
	if ( defined( 'BV_HTML' ) ) {
		http_response_code( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );
		exit( $message );
	}
	bv_json( array( 'error' => $message ), $status );
}

function bv_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

// Database ----------------------------------------------------------------------------------

/** One PDO connection per request. MySQL on the live site; SQLite works for local testing. */
function bv_db() {
	static $pdo = null;
	if ( null === $pdo ) {
		$db = bv_config()['db'];
		if ( empty( $db['dsn'] ) ) {
			bv_fail( 'The database is not set up yet (db settings in api/config.php).', 500 );
		}
		try {
			$pdo = new PDO(
				$db['dsn'],
				isset( $db['user'] ) ? $db['user'] : null,
				isset( $db['password'] ) ? $db['password'] : null,
				array(
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES   => false,
				)
			);
		} catch ( PDOException $e ) {
			error_log( 'BabyVibes: database connection failed: ' . $e->getMessage() );
			bv_fail( 'The shop is temporarily unavailable. Please try again in a minute.', 503 );
		}
		if ( 'sqlite' === $pdo->getAttribute( PDO::ATTR_DRIVER_NAME ) ) {
			$pdo->exec( 'PRAGMA foreign_keys = ON' );
		}
	}
	return $pdo;
}

function bv_q( $sql, array $params = array() ) {
	$st = bv_db()->prepare( $sql );
	$st->execute( $params );
	return $st;
}

function bv_row( $sql, array $params = array() ) {
	$r = bv_q( $sql, $params )->fetch();
	return $r ? $r : null;
}

function bv_rows( $sql, array $params = array() ) {
	return bv_q( $sql, $params )->fetchAll();
}

function bv_insert( $table, array $data ) {
	$cols = array_keys( $data );
	bv_q(
		"INSERT INTO $table (" . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', array_fill( 0, count( $cols ), '?' ) ) . ')',
		array_values( $data )
	);
	return bv_db()->lastInsertId();
}

function bv_update( $table, array $data, $where, array $params ) {
	$set = array();
	foreach ( array_keys( $data ) as $c ) {
		$set[] = "$c = ?";
	}
	return bv_q( "UPDATE $table SET " . implode( ', ', $set ) . " WHERE $where", array_merge( array_values( $data ), $params ) )->rowCount();
}

function bv_setting( $key, $value = null ) {
	if ( null === $value ) {
		$r = bv_row( 'SELECT v FROM settings WHERE k = ?', array( $key ) );
		return $r ? $r['v'] : null;
	}
	if ( bv_row( 'SELECT k FROM settings WHERE k = ?', array( $key ) ) ) {
		bv_update( 'settings', array( 'v' => $value ), 'k = ?', array( $key ) );
	} else {
		bv_insert( 'settings', array( 'k' => $key, 'v' => $value ) );
	}
	return $value;
}

function bv_webhook_secret() {
	$c = bv_config()['yoco_webhook_secret'];
	return $c ? $c : (string) bv_setting( 'yoco_webhook_secret' );
}

// Catalogue ---------------------------------------------------------------------------------

function bv_read_json( $relative ) {
	$data = json_decode( (string) file_get_contents( bv_root() . '/' . $relative ), true );
	if ( ! is_array( $data ) ) {
		bv_fail( "Could not read $relative.", 500 );
	}
	return $data;
}

function bv_shop() {
	static $shop = null;
	return $shop ? $shop : ( $shop = bv_read_json( 'data/shop.json' ) );
}

/** Products keyed by slug. */
function bv_products() {
	static $products = null;
	if ( null === $products ) {
		$products = array();
		foreach ( bv_read_json( 'data/products.json' )['products'] as $p ) {
			$products[ $p['slug'] ] = $p;
		}
	}
	return $products;
}

/** Amounts are handled in cents to avoid rounding errors. */
function bv_cents( $rand ) {
	return (int) round( (float) $rand * 100 );
}

function bv_rand( $cents ) {
	return 'R' . number_format( $cents / 100, 2, '.', ' ' );
}

function bv_delivery_cents( $subtotal_cents ) {
	$d = bv_shop()['delivery'];
	if ( ! empty( $d['freeFrom'] ) && $subtotal_cents >= bv_cents( $d['freeFrom'] ) ) {
		return 0;
	}
	return bv_cents( $d['flatRate'] );
}

/**
 * Turn the browser's cart ({"slug" or "slug|Option": qty}) into priced lines using the server's
 * own product list. Prices sent by the browser are never trusted.
 */
function bv_price_cart( $cart ) {
	$products = bv_products();
	$lines    = array();
	$errors   = array();
	$subtotal = 0;
	foreach ( (array) $cart as $key => $qty ) {
		$qty = (int) $qty;
		if ( $qty < 1 ) {
			continue;
		}
		$parts  = explode( '|', (string) $key, 2 );
		$slug   = $parts[0];
		$option = isset( $parts[1] ) ? $parts[1] : '';
		if ( ! isset( $products[ $slug ] ) ) {
			$errors[] = 'A product in your cart is no longer available.';
			continue;
		}
		$p = $products[ $slug ];
		if ( ! empty( $p['options'] ) ) {
			$allowed = array_column( $p['options']['values'], 'value' );
			if ( ! in_array( $option, $allowed, true ) ) {
				$errors[] = 'Please choose a ' . strtolower( $p['options']['name'] ) . ' for ' . $p['name'] . '.';
				continue;
			}
		} else {
			$option = '';
		}
		if ( empty( $p['inStock'] ) ) {
			$errors[] = $p['name'] . ' is out of stock.';
			continue;
		}
		$qty       = min( $qty, 20 );
		$unit      = bv_cents( $p['price'] );
		$lines[]   = array(
			'slug'   => $slug,
			'name'   => $p['name'] . ( '' !== $option ? ' (' . $p['options']['name'] . ': ' . $option . ')' : '' ),
			'option' => $option,
			'qty'    => $qty,
			'unit'   => $unit,
			'total'  => $unit * $qty,
		);
		$subtotal += $unit * $qty;
	}
	$delivery = $lines ? bv_delivery_cents( $subtotal ) : 0;
	return array(
		'lines'    => $lines,
		'subtotal' => $subtotal,
		'delivery' => $delivery,
		'total'    => $subtotal + $delivery,
		'errors'   => $errors,
	);
}

// Orders ------------------------------------------------------------------------------------

function bv_new_order_id() {
	return 'BV' . gmdate( 'ymd' ) . '-' . strtoupper( bin2hex( random_bytes( 3 ) ) );
}

function bv_valid_order_id( $id ) {
	return (bool) preg_match( '/^BV\d{6}-[0-9A-F]{6}$/', (string) $id );
}

/** Saves a new order with its items. $o uses the same shape bv_load_order() returns. */
function bv_create_order( array $o ) {
	$db = bv_db();
	$db->beginTransaction();
	$c = $o['customer'];
	bv_insert(
		'orders',
		array(
			'id'          => $o['id'],
			'token'       => $o['token'],
			'status'      => 'pending',
			'customer_id' => $o['customer_id'],
			'email'       => $c['email'],
			'first_name'  => $c['firstName'],
			'last_name'   => $c['lastName'],
			'phone'       => $c['phone'],
			'address1'    => $c['address1'],
			'address2'    => $c['address2'],
			'suburb'      => $c['suburb'],
			'city'        => $c['city'],
			'province'    => $c['province'],
			'postcode'    => $c['postcode'],
			'notes'       => $c['notes'],
			'subtotal'    => $o['subtotal'],
			'delivery'    => $o['delivery'],
			'total'       => $o['total'],
			'currency'    => 'ZAR',
			'created_at'  => bv_now(),
			'updated_at'  => bv_now(),
		)
	);
	foreach ( $o['lines'] as $l ) {
		bv_insert(
			'order_items',
			array(
				'order_id'     => $o['id'],
				'slug'         => $l['slug'],
				'name'         => $l['name'],
				'option_value' => $l['option'],
				'qty'          => $l['qty'],
				'unit'         => $l['unit'],
				'total'        => $l['total'],
			)
		);
	}
	bv_order_event( $o['id'], 'created', 'Order placed, waiting for payment.' );
	$db->commit();
	return bv_load_order( $o['id'] );
}

/** Loads an order with its items and history, or null. */
function bv_load_order( $id ) {
	if ( ! bv_valid_order_id( $id ) ) {
		return null;
	}
	$r = bv_row( 'SELECT * FROM orders WHERE id = ?', array( $id ) );
	return $r ? bv_order_from_row( $r ) : null;
}

function bv_find_order_by_checkout( $checkout_id ) {
	$r = bv_row( 'SELECT * FROM orders WHERE checkout_id = ?', array( (string) $checkout_id ) );
	return $r ? bv_order_from_row( $r ) : null;
}

function bv_order_from_row( array $r ) {
	$items = bv_rows( 'SELECT slug, name, option_value, qty, unit, total FROM order_items WHERE order_id = ? ORDER BY id', array( $r['id'] ) );
	return array(
		'id'          => $r['id'],
		'token'       => $r['token'],
		'status'      => $r['status'],
		'customer_id' => $r['customer_id'] ? (int) $r['customer_id'] : null,
		'customer'    => array(
			'firstName' => $r['first_name'],
			'lastName'  => $r['last_name'],
			'email'     => $r['email'],
			'phone'     => $r['phone'],
			'address1'  => $r['address1'],
			'address2'  => $r['address2'],
			'suburb'    => $r['suburb'],
			'city'      => $r['city'],
			'province'  => $r['province'],
			'postcode'  => $r['postcode'],
			'notes'     => $r['notes'],
		),
		'lines'       => array_map(
			function ( $i ) {
				return array(
					'slug'   => $i['slug'],
					'name'   => $i['name'],
					'option' => $i['option_value'],
					'qty'    => (int) $i['qty'],
					'unit'   => (int) $i['unit'],
					'total'  => (int) $i['total'],
				);
			},
			$items
		),
		'subtotal'    => (int) $r['subtotal'],
		'delivery'    => (int) $r['delivery'],
		'total'       => (int) $r['total'],
		'checkoutId'  => $r['checkout_id'],
		'payment'     => array(
			'id'     => $r['payment_id'],
			'amount' => (int) $r['payment_amount'],
			'mode'   => $r['payment_mode'],
		),
		'tracking'    => array(
			'courier' => $r['courier'],
			'number'  => $r['tracking_number'],
			'url'     => $r['tracking_url'],
		),
		'adminNote'   => $r['admin_note'],
		'created'     => $r['created_at'],
		'paid'        => $r['paid_at'],
		'shipped'     => $r['shipped_at'],
		'delivered'   => $r['delivered_at'],
		'cancelled'   => $r['cancelled_at'],
	);
}

function bv_update_order( $id, array $columns ) {
	$columns['updated_at'] = bv_now();
	bv_update( 'orders', $columns, 'id = ?', array( $id ) );
}

/** Adds a line to an order's history. $public lines are shown to the customer on the tracking page. */
function bv_order_event( $order_id, $type, $message, $public = true ) {
	bv_insert(
		'order_events',
		array(
			'order_id'   => $order_id,
			'type'       => $type,
			'message'    => $message,
			'public'     => $public ? 1 : 0,
			'created_at' => bv_now(),
		)
	);
}

function bv_order_events( $order_id, $public_only = false ) {
	return bv_rows(
		'SELECT type, message, created_at FROM order_events WHERE order_id = ?' . ( $public_only ? ' AND public = 1' : '' ) . ' ORDER BY id',
		array( $order_id )
	);
}

/** What a customer may see about an order: no internal notes, no payment ids. */
function bv_public_order( array $o, $with_address = false ) {
	$out = array(
		'id'          => $o['id'],
		'status'      => $o['status'],
		'statusLabel' => isset( BV_STATUSES[ $o['status'] ] ) ? BV_STATUSES[ $o['status'] ] : $o['status'],
		'created'     => $o['created'],
		'firstName'   => $o['customer']['firstName'],
		'lines'       => array_map(
			function ( $l ) {
				return array( 'name' => $l['name'], 'slug' => $l['slug'], 'qty' => $l['qty'], 'total' => $l['total'] );
			},
			$o['lines']
		),
		'subtotal'    => $o['subtotal'],
		'delivery'    => $o['delivery'],
		'total'       => $o['total'],
		'tracking'    => $o['tracking']['number'] ? $o['tracking'] : null,
		'events'      => bv_order_events( $o['id'], true ),
	);
	if ( $with_address ) {
		$out['address'] = bv_address( $o['customer'] );
	}
	return $out;
}

function bv_address( array $c ) {
	return implode( "\n", array_filter( array( $c['address1'], $c['address2'], $c['suburb'], trim( $c['city'] . ' ' . $c['postcode'] ), $c['province'] ) ) );
}

// Customers ---------------------------------------------------------------------------------

function bv_customer_by_email( $email ) {
	return bv_row( 'SELECT * FROM customers WHERE email = ?', array( strtolower( trim( (string) $email ) ) ) );
}

function bv_customer( $id ) {
	return bv_row( 'SELECT * FROM customers WHERE id = ?', array( (int) $id ) );
}

/** Customer details that are safe to send to the browser. */
function bv_public_customer( array $c ) {
	return array(
		'firstName' => $c['first_name'],
		'lastName'  => $c['last_name'],
		'email'     => $c['email'],
		'phone'     => $c['phone'],
		'address1'  => $c['address1'],
		'address2'  => $c['address2'],
		'suburb'    => $c['suburb'],
		'city'      => $c['city'],
		'province'  => $c['province'],
		'postcode'  => $c['postcode'],
		'marketing' => (bool) $c['marketing_opt_in'],
	);
}

function bv_create_customer( array $d ) {
	$id = bv_insert(
		'customers',
		array(
			'email'            => strtolower( $d['email'] ),
			'password_hash'    => password_hash( $d['password'], PASSWORD_DEFAULT ),
			'first_name'       => $d['firstName'],
			'last_name'        => $d['lastName'],
			'phone'            => isset( $d['phone'] ) ? $d['phone'] : '',
			'address1'         => isset( $d['address1'] ) ? $d['address1'] : '',
			'address2'         => isset( $d['address2'] ) ? $d['address2'] : '',
			'suburb'           => isset( $d['suburb'] ) ? $d['suburb'] : '',
			'city'             => isset( $d['city'] ) ? $d['city'] : '',
			'province'         => isset( $d['province'] ) ? $d['province'] : '',
			'postcode'         => isset( $d['postcode'] ) ? $d['postcode'] : '',
			'marketing_opt_in' => ! empty( $d['marketing'] ) ? 1 : 0,
			'created_at'       => bv_now(),
		)
	);
	return bv_customer( $id );
}

// Sessions and security ---------------------------------------------------------------------

function bv_https() {
	return ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );
}

function bv_session() {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		return;
	}
	session_name( 'bv_session' );
	session_set_cookie_params(
		array(
			'lifetime' => 0,
			'path'     => '/',
			'secure'   => bv_https(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
	ini_set( 'session.use_strict_mode', '1' );
	session_start();
}

function bv_current_customer() {
	bv_session();
	if ( empty( $_SESSION['customer_id'] ) ) {
		return null;
	}
	$c = bv_customer( $_SESSION['customer_id'] );
	if ( ! $c ) {
		unset( $_SESSION['customer_id'] );
	}
	return $c;
}

function bv_login_customer( array $c ) {
	bv_session();
	session_regenerate_id( true );
	$_SESSION['customer_id'] = (int) $c['id'];
	bv_update( 'customers', array( 'last_login_at' => bv_now() ), 'id = ?', array( $c['id'] ) );
}

/**
 * API calls that change something must come from the shop's own pages. Browsers only let a page
 * send this custom header to its own site, which blocks cross-site form attacks (CSRF).
 */
function bv_require_same_site() {
	if ( ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) !== 'BabyVibes' ) {
		bv_fail( 'Invalid request.', 400 );
	}
}

function bv_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'cli';
}

/** Simple rate limiting: true if $key has had $max or more attempts of $kind within $minutes. */
function bv_rate_limited( $kind, $key, $max, $minutes ) {
	$since = gmdate( 'Y-m-d H:i:s', time() - $minutes * 60 );
	$n     = bv_row( 'SELECT COUNT(*) AS n FROM attempts WHERE kind = ? AND key_hash = ? AND created_at > ?', array( $kind, hash( 'sha256', $key ), $since ) );
	return (int) $n['n'] >= $max;
}

function bv_record_attempt( $kind, $key ) {
	bv_insert( 'attempts', array( 'kind' => $kind, 'key_hash' => hash( 'sha256', $key ), 'created_at' => bv_now() ) );
	if ( 1 === random_int( 1, 50 ) ) {
		bv_q( 'DELETE FROM attempts WHERE created_at < ?', array( gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) );
	}
}

function bv_input() {
	$in = json_decode( (string) file_get_contents( 'php://input' ), true );
	return is_array( $in ) ? $in : array();
}

/** Cleans one text field from user input. */
function bv_clean( array $in, $key, $max = 120 ) {
	$v = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
	return mb_substr( trim( preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $v ) ), 0, $max );
}

const BV_PROVINCES = array( 'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape' );

/** Normalises a South African cellphone number to 0XXXXXXXXX, or '' if it isn't one. */
function bv_phone( $raw ) {
	$d = preg_replace( '/\D+/', '', (string) $raw );
	if ( 0 === strpos( $d, '27' ) ) {
		$d = '0' . substr( $d, 2 );
	}
	return preg_match( '/^0\d{9}$/', $d ) ? $d : '';
}

/**
 * Reads and checks the delivery-address fields shared by checkout and account pages.
 * Returns [values, errors].
 */
function bv_address_fields( array $in, $required = true ) {
	$v = array(
		'address1' => bv_clean( $in, 'address1' ),
		'address2' => bv_clean( $in, 'address2' ),
		'suburb'   => bv_clean( $in, 'suburb', 80 ),
		'city'     => bv_clean( $in, 'city', 80 ),
		'province' => bv_clean( $in, 'province', 40 ),
		'postcode' => bv_clean( $in, 'postcode', 4 ),
	);
	$e = array();
	$any = implode( '', $v ) !== '';
	if ( $required || $any ) {
		foreach ( array( 'address1' => 'street address', 'suburb' => 'suburb', 'city' => 'city or town' ) as $k => $label ) {
			if ( '' === $v[ $k ] ) {
				$e[ $k ] = "Please enter your $label.";
			}
		}
		if ( ! in_array( $v['province'], BV_PROVINCES, true ) ) {
			$e['province'] = 'Please choose your province.';
		}
		if ( ! preg_match( '/^\d{4}$/', $v['postcode'] ) ) {
			$e['postcode'] = 'Please enter your 4-digit postal code.';
		}
	}
	return array( $v, $e );
}

// Yoco --------------------------------------------------------------------------------------

/**
 * Calls the Yoco API. Returns [http status, decoded body].
 * Tests replace this through the BV_YOCO_MOCK constant.
 */
function bv_yoco_request( $method, $path, $body = null, $idempotency_key = null ) {
	if ( defined( 'BV_YOCO_MOCK' ) ) {
		return call_user_func( BV_YOCO_MOCK, $method, $path, $body );
	}
	$headers = array(
		'Authorization: Bearer ' . bv_config()['yoco_secret_key'],
		'Content-Type: application/json',
	);
	if ( $idempotency_key ) {
		$headers[] = 'Idempotency-Key: ' . $idempotency_key;
	}
	$ch = curl_init( BV_YOCO_API . $path );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
		)
	);
	if ( null !== $body ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
	}
	$raw    = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( $status, json_decode( (string) $raw, true ) );
}

/**
 * Checks a Yoco webhook signature (Standard Webhooks format).
 * Signed content is "{webhook-id}.{webhook-timestamp}.{raw body}", HMAC-SHA256 with the
 * base64-decoded part of the secret after "whsec_".
 */
function bv_verify_webhook( $raw_body, $id, $timestamp, $signature_header, $secret, $now = null ) {
	if ( ! $secret || ! $id || ! $timestamp || ! $signature_header ) {
		return false;
	}
	$now = $now ? $now : time();
	if ( abs( $now - (int) $timestamp ) > 180 ) {
		return false; // Too old or from the future: possible replay.
	}
	$key      = base64_decode( preg_replace( '/^whsec_/', '', $secret ) );
	$expected = base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $raw_body, $key, true ) );
	foreach ( preg_split( '/\s+/', trim( $signature_header ) ) as $sig ) {
		$parts = explode( ',', $sig, 2 );
		$value = 2 === count( $parts ) ? $parts[1] : $parts[0];
		if ( hash_equals( $expected, $value ) ) {
			return true;
		}
	}
	return false;
}

// HTTP --------------------------------------------------------------------------------------

function bv_json( $data, $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	header( 'X-Content-Type-Options: nosniff' );
	echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

require_once __DIR__ . '/emails.php';
