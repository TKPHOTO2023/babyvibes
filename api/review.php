<?php
/**
 * POST /api/review.php
 * Body (JSON): { order, email, name, location, rating, professionalism, convenience, text }
 * Only customers with a paid order can review: the order number and email must match.
 * Reviews wait for approval in the admin (admin/reviews.php); the shop gets an email.
 */

require __DIR__ . '/lib.php';

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	bv_fail( 'Method not allowed.', 405 );
}
bv_require_same_site();
$in = bv_input();
if ( ! empty( $in['website'] ) ) {
	bv_fail( 'Invalid request.', 400 );
}
if ( bv_rate_limited( 'review', bv_client_ip(), 10, 60 ) ) {
	bv_fail( 'Too many attempts. Please try again later.', 429 );
}
bv_record_attempt( 'review', bv_client_ip() );

$stars = function ( $key ) use ( $in ) {
	$v = isset( $in[ $key ] ) ? (int) $in[ $key ] : 0;
	return ( $v >= 1 && $v <= 5 ) ? $v : 0;
};

$order_id = strtoupper( bv_clean( $in, 'order', 20 ) );
$email    = strtolower( bv_clean( $in, 'email', 120 ) );
$review   = array(
	'name'            => bv_clean( $in, 'name', 40 ),
	'location'        => bv_clean( $in, 'location', 40 ),
	'rating'          => $stars( 'rating' ),
	'professionalism' => $stars( 'professionalism' ),
	'convenience'     => $stars( 'convenience' ),
	'text'            => bv_clean( $in, 'text', 800 ),
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
if ( ! $order || ! in_array( $order['status'], array( 'paid', 'shipped', 'delivered' ), true ) || ! hash_equals( strtolower( $order['customer']['email'] ), $email ) ) {
	$errors['order'] = "We couldn't find a paid order with that order number and email. Check your order confirmation email.";
}
if ( $errors ) {
	bv_json( array( 'error' => 'Please check the highlighted details.', 'fields' => $errors ), 422 );
}
if ( bv_row( 'SELECT id FROM reviews WHERE order_id = ?', array( $order_id ) ) ) {
	bv_fail( "You've already reviewed this order. Thank you!", 409 );
}

$review = array_merge(
	$review,
	array(
		'order_id'   => $order_id,
		'products'   => implode( ', ', array_column( $order['lines'], 'name' ) ),
		'status'     => 'pending',
		'created_at' => bv_now(),
	)
);
bv_insert( 'reviews', $review );
bv_email_review_admin( $review );

bv_json( array( 'ok' => true ) );
