<?php
/**
 * Shared code for the BabyVibes checkout: settings, products, orders, Yoco and email.
 * Not a page: the .htaccess in this folder blocks direct requests to it.
 */

const BV_YOCO_API = 'https://payments.yoco.com/api';

function bv_root() {
	return dirname( __DIR__ );
}

function bv_config() {
	static $config = null;
	if ( null === $config ) {
		$file = defined( 'BV_CONFIG' ) ? BV_CONFIG : __DIR__ . '/config.php';
		if ( ! is_readable( $file ) ) {
			bv_json( array( 'error' => 'The shop is not set up yet (api/config.php is missing).' ), 500 );
		}
		$config = require $file;
		if ( empty( $config['yoco_webhook_secret'] ) ) {
			$saved = bv_orders_dir( $config ) . '/.webhook.json';
			if ( is_readable( $saved ) ) {
				$data                          = json_decode( (string) file_get_contents( $saved ), true );
				$config['yoco_webhook_secret'] = isset( $data['secret'] ) ? $data['secret'] : '';
			}
		}
	}
	return $config;
}

function bv_orders_dir( $config = null ) {
	$config = $config ? $config : bv_config();
	$dir    = rtrim( $config['orders_dir'], '/' );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0750, true );
	}
	return $dir;
}

function bv_read_json( $relative ) {
	$data = json_decode( (string) file_get_contents( bv_root() . '/' . $relative ), true );
	if ( ! is_array( $data ) ) {
		bv_json( array( 'error' => "Could not read $relative." ), 500 );
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
 * Turn the browser's cart ({slug: qty}) into priced lines using the server's own product list.
 * Prices sent by the browser are never trusted.
 *
 * @return array{lines: array, subtotal: int, delivery: int, total: int, errors: string[]}
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
		// Cart keys are "slug" or "slug|Option value" (for example a colour).
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
			'slug'  => $slug,
			'name'  => $p['name'] . ( '' !== $option ? ' (' . $p['options']['name'] . ': ' . $option . ')' : '' ),
			'qty'   => $qty,
			'unit'  => $unit,
			'total' => $unit * $qty,
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

function bv_order_path( $id ) {
	if ( ! preg_match( '/^BV\d{6}-[0-9A-F]{6}$/', (string) $id ) ) {
		return null;
	}
	return bv_orders_dir() . '/' . $id . '.json';
}

function bv_load_order( $id ) {
	$path = bv_order_path( $id );
	if ( ! $path || ! is_readable( $path ) ) {
		return null;
	}
	return json_decode( (string) file_get_contents( $path ), true );
}

function bv_save_order( array $order ) {
	$order['updated'] = gmdate( 'c' );
	$path             = bv_order_path( $order['id'] );
	file_put_contents( $path, json_encode( $order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), LOCK_EX );
	return $order;
}

/** Finds an order by its Yoco checkout id. */
function bv_find_order_by_checkout( $checkout_id ) {
	$index = bv_orders_dir() . '/checkouts.json';
	$map   = is_readable( $index ) ? json_decode( (string) file_get_contents( $index ), true ) : array();
	return isset( $map[ $checkout_id ] ) ? bv_load_order( $map[ $checkout_id ] ) : null;
}

function bv_index_checkout( $checkout_id, $order_id ) {
	$index = bv_orders_dir() . '/checkouts.json';
	$fp    = fopen( $index, 'c+' );
	flock( $fp, LOCK_EX );
	$map                 = json_decode( (string) stream_get_contents( $fp ), true );
	$map                 = is_array( $map ) ? $map : array();
	$map[ $checkout_id ] = $order_id;
	ftruncate( $fp, 0 );
	rewind( $fp );
	fwrite( $fp, json_encode( $map ) );
	flock( $fp, LOCK_UN );
	fclose( $fp );
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

// Email -------------------------------------------------------------------------------------

function bv_mail( $to, $subject, $text ) {
	if ( defined( 'BV_MAIL_LOG' ) ) {
		file_put_contents( BV_MAIL_LOG, "To: $to\nSubject: $subject\n\n$text\n-----\n", FILE_APPEND );
		return true;
	}
	$from    = bv_config()['from_email'];
	$headers = array(
		'From: ' . bv_shop()['name'] . ' <' . $from . '>',
		'Reply-To: ' . bv_shop()['email'],
		'Content-Type: text/plain; charset=UTF-8',
	);
	return mail( $to, '=?UTF-8?B?' . base64_encode( $subject ) . '?=', $text, implode( "\r\n", $headers ) );
}

function bv_order_summary( array $order ) {
	$out = array();
	foreach ( $order['lines'] as $l ) {
		$out[] = sprintf( '%d x %s  %s', $l['qty'], $l['name'], bv_rand( $l['total'] ) );
	}
	$out[] = 'Delivery: ' . ( $order['delivery'] ? bv_rand( $order['delivery'] ) : 'Free' );
	$out[] = 'Total: ' . bv_rand( $order['total'] );
	return implode( "\n", $out );
}

function bv_send_order_emails( array $order ) {
	$shop  = bv_shop();
	$c     = $order['customer'];
	$admin = bv_config()['orders_email'] ? bv_config()['orders_email'] : $shop['email'];

	bv_mail(
		$admin,
		"New paid order {$order['id']}",
		"A new order has been paid.\n\nOrder: {$order['id']}\nYoco payment: {$order['payment']['id']}\n\n"
		. bv_order_summary( $order )
		. "\n\nCustomer\n{$c['firstName']} {$c['lastName']}\n{$c['email']}\n{$c['phone']}\n\nDeliver to\n"
		. bv_address( $c )
		. ( $c['notes'] ? "\n\nNotes\n{$c['notes']}" : '' )
	);

	bv_mail(
		$c['email'],
		"Thank you for your order ({$order['id']})",
		"Hi {$c['firstName']},\n\nThank you for shopping with {$shop['name']}! We've received your payment and will "
		. "pack your order soon. We'll email your tracking number when it ships.\n\nOrder {$order['id']}\n"
		. bv_order_summary( $order )
		. "\n\nDelivering to\n" . bv_address( $c )
		. "\n\nQuestions? Reply to this email or WhatsApp us on +{$shop['whatsapp']}.\n\n"
		. "Once your order arrives, we'd love to hear what you think. It only takes a minute:\n"
		. rtrim( bv_config()['site_url'], '/' ) . "/review.html?order={$order['id']}\n\n"
		. "Love,\nThe {$shop['name']} team"
	);
}

function bv_address( array $c ) {
	return implode( "\n", array_filter( array( $c['address1'], $c['address2'], $c['suburb'], $c['city'] . ' ' . $c['postcode'], $c['province'] ) ) );
}

// HTTP --------------------------------------------------------------------------------------

function bv_json( $data, $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}
