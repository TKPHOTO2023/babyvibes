<?php
/**
 * POST /api/review.php
 * Body (JSON): { order, email, name, location, rating, professionalism, convenience, text }
 * Only customers with a paid order can review: the order number and email must match.
 * Reviews wait for approval in <orders_dir>/reviews-pending.json and the shop gets an email.
 * Approved reviews go into data/reviews.json (see README).
 */

require __DIR__ . '/lib.php';

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	bv_json( array( 'error' => 'Method not allowed.' ), 405 );
}

$in = json_decode( (string) file_get_contents( 'php://input' ), true );
if ( ! is_array( $in ) || ! empty( $in['website'] ) ) {
	bv_json( array( 'error' => 'Invalid request.' ), 400 );
}

$clean = function ( $key, $max ) use ( $in ) {
	$v = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
	return mb_substr( trim( preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $v ) ), 0, $max );
};
$stars = function ( $key ) use ( $in ) {
	$v = isset( $in[ $key ] ) ? (int) $in[ $key ] : 0;
	return ( $v >= 1 && $v <= 5 ) ? $v : 0;
};

$order_id = strtoupper( $clean( 'order', 20 ) );
$email    = strtolower( $clean( 'email', 120 ) );
$review   = array(
	'name'            => $clean( 'name', 40 ),
	'location'        => $clean( 'location', 40 ),
	'rating'          => $stars( 'rating' ),
	'professionalism' => $stars( 'professionalism' ),
	'convenience'     => $stars( 'convenience' ),
	'text'            => $clean( 'text', 800 ),
);

$errors = array();
if ( '' === $review['name'] ) {
	$errors['name'] = 'Please add your name as you want it shown, for example "Thandi M."';
}
if ( ! $review['rating'] ) {
	$errors['rating'] = 'Please choose a star rating.';
}
if ( mb_strlen( $review['text'] ) < 10 ) {
	$errors['text'] = 'Please tell us a little about your experience.';
}

$order = bv_load_order( $order_id );
if ( ! $order || 'paid' !== $order['status'] || ! hash_equals( $order['customer']['email'], $email ) ) {
	$errors['order'] = "We couldn't find a paid order with that order number and email. Check your order confirmation email.";
}
if ( $errors ) {
	bv_json( array( 'error' => 'Please check the highlighted details.', 'fields' => $errors ), 422 );
}

// One review per order.
$file = bv_orders_dir() . '/reviews-pending.json';
$fp   = fopen( $file, 'c+' );
flock( $fp, LOCK_EX );
$pending = json_decode( (string) stream_get_contents( $fp ), true );
$pending = is_array( $pending ) ? $pending : array();
foreach ( $pending as $r ) {
	if ( $r['order'] === $order_id ) {
		flock( $fp, LOCK_UN );
		fclose( $fp );
		bv_json( array( 'error' => "You've already reviewed this order. Thank you!" ), 409 );
	}
}
$review = array_merge(
	$review,
	array(
		'order'    => $order_id,
		'date'     => gmdate( 'Y-m-d' ),
		'verified' => true,
		'products' => array_map( function ( $l ) { return $l['name']; }, $order['lines'] ),
	)
);
$pending[] = $review;
ftruncate( $fp, 0 );
rewind( $fp );
fwrite( $fp, json_encode( $pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
flock( $fp, LOCK_UN );
fclose( $fp );

// Email the shop, with the entry ready to paste into data/reviews.json.
$public = array(
	'name'            => $review['name'],
	'location'        => $review['location'],
	'rating'          => $review['rating'],
	'professionalism' => $review['professionalism'],
	'convenience'     => $review['convenience'],
	'text'            => $review['text'],
	'date'            => $review['date'],
	'verified'        => true,
);
bv_mail(
	bv_config()['orders_email'] ? bv_config()['orders_email'] : bv_shop()['email'],
	"New {$review['rating']}-star review from {$review['name']} ({$order_id})",
	"A verified customer left a review. It is not on the website yet.\n\n"
	. "Overall: {$review['rating']}/5\nProfessionalism: " . ( $review['professionalism'] ? $review['professionalism'] . '/5' : '-' )
	. "\nConvenience: " . ( $review['convenience'] ? $review['convenience'] . '/5' : '-' )
	. "\n\n\"{$review['text']}\"\n\n{$review['name']}" . ( $review['location'] ? ", {$review['location']}" : '' )
	. "\nOrder {$order_id}: " . implode( ', ', $review['products'] )
	. "\n\nTo publish it, add this to the \"reviews\" list in data/reviews.json and upload the file:\n\n"
	. json_encode( $public, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"
);

bv_json( array( 'ok' => true ) );
