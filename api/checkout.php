<?php
/**
 * POST /api/checkout.php
 * Body (JSON): { "cart": { "<slug>": qty, ... }, "customer": { ... } }
 * Re-prices the cart from data/products.json, saves a pending order and creates a Yoco
 * checkout. Responds with { "redirectUrl": "..." } to send the shopper to Yoco's payment page.
 */

require __DIR__ . '/lib.php';

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	bv_json( array( 'error' => 'Method not allowed.' ), 405 );
}

$input = json_decode( (string) file_get_contents( 'php://input' ), true );
if ( ! is_array( $input ) ) {
	bv_json( array( 'error' => 'Invalid request.' ), 400 );
}

// Spam trap: a hidden field people never fill in.
if ( ! empty( $input['website'] ) ) {
	bv_json( array( 'error' => 'Invalid request.' ), 400 );
}

// Customer details ----------------------------------------------------------------------------

$provinces = array( 'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape' );
$raw       = isset( $input['customer'] ) && is_array( $input['customer'] ) ? $input['customer'] : array();
$field     = function ( $key, $max = 120 ) use ( $raw ) {
	$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
	$value = trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) );
	return mb_substr( $value, 0, $max );
};

$customer = array(
	'firstName' => $field( 'firstName', 60 ),
	'lastName'  => $field( 'lastName', 60 ),
	'email'     => strtolower( $field( 'email', 120 ) ),
	'phone'     => $field( 'phone', 20 ),
	'address1'  => $field( 'address1' ),
	'address2'  => $field( 'address2' ),
	'suburb'    => $field( 'suburb', 80 ),
	'city'      => $field( 'city', 80 ),
	'province'  => $field( 'province', 40 ),
	'postcode'  => $field( 'postcode', 4 ),
	'notes'     => $field( 'notes', 500 ),
);

$errors = array();
foreach ( array(
	'firstName' => 'first name',
	'lastName'  => 'last name',
	'address1'  => 'street address',
	'suburb'    => 'suburb',
	'city'      => 'city or town',
) as $key => $label ) {
	if ( '' === $customer[ $key ] ) {
		$errors[ $key ] = "Please enter your $label.";
	}
}
if ( ! filter_var( $customer['email'], FILTER_VALIDATE_EMAIL ) ) {
	$errors['email'] = 'Please enter a valid email address.';
}
$digits = preg_replace( '/\D+/', '', $customer['phone'] );
if ( 0 === strpos( $digits, '27' ) ) {
	$digits = '0' . substr( $digits, 2 );
}
if ( ! preg_match( '/^0\d{9}$/', $digits ) ) {
	$errors['phone'] = 'Please enter a 10-digit South African phone number, for example 082 123 4567.';
}
$customer['phone'] = $digits;
if ( ! in_array( $customer['province'], $provinces, true ) ) {
	$errors['province'] = 'Please choose your province.';
}
if ( ! preg_match( '/^\d{4}$/', $customer['postcode'] ) ) {
	$errors['postcode'] = 'Please enter your 4-digit postal code.';
}

// Cart --------------------------------------------------------------------------------------

$priced = bv_price_cart( isset( $input['cart'] ) ? $input['cart'] : array() );
if ( $priced['errors'] ) {
	$errors['cart'] = implode( ' ', $priced['errors'] ) . ' Please check your cart.';
} elseif ( ! $priced['lines'] ) {
	$errors['cart'] = 'Your cart is empty.';
}

if ( $errors ) {
	bv_json( array( 'error' => 'Please check the highlighted details.', 'fields' => $errors ), 422 );
}

// Order + Yoco checkout ---------------------------------------------------------------------

$config = bv_config();
$id     = bv_new_order_id();
$token  = bin2hex( random_bytes( 16 ) );
$site   = rtrim( $config['site_url'], '/' );

$order = bv_save_order(
	array(
		'id'       => $id,
		'token'    => $token,
		'status'   => 'pending',
		'created'  => gmdate( 'c' ),
		'customer' => $customer,
		'lines'    => $priced['lines'],
		'subtotal' => $priced['subtotal'],
		'delivery' => $priced['delivery'],
		'total'    => $priced['total'],
		'currency' => 'ZAR',
	)
);

list( $status, $body ) = bv_yoco_request(
	'POST',
	'/checkouts',
	array(
		'amount'     => $order['total'],
		'currency'   => 'ZAR',
		'successUrl' => "$site/thank-you.html?order=$id&t=$token",
		'cancelUrl'  => "$site/checkout.html?cancelled=1",
		'failureUrl' => "$site/checkout.html?failed=1",
		'metadata'   => array(
			'orderId' => $id,
			'email'   => $customer['email'],
		),
	),
	$id
);

if ( $status < 200 || $status >= 300 || empty( $body['id'] ) || empty( $body['redirectUrl'] ) ) {
	$order['status']     = 'checkout_failed';
	$order['yocoError']  = array( 'status' => $status, 'body' => $body );
	bv_save_order( $order );
	error_log( "BabyVibes: Yoco checkout failed for $id (HTTP $status): " . json_encode( $body ) );
	bv_json( array( 'error' => 'We could not open the payment page. Please try again in a minute, or WhatsApp us.' ), 502 );
}

$order['checkoutId'] = $body['id'];
bv_save_order( $order );
bv_index_checkout( $body['id'], $id );

bv_json( array( 'redirectUrl' => $body['redirectUrl'], 'order' => $id ) );
