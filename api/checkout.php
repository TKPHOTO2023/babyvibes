<?php
/**
 * POST /api/checkout.php
 * Body (JSON): { cart: {"<slug>" or "<slug>|<option>": qty}, customer: {...}, createAccount, password }
 * Re-prices the cart from data/products.json, saves a pending order (linked to the signed-in
 * customer, or to a new account if the shopper asked for one) and creates a Yoco checkout.
 * Responds with { redirectUrl } to send the shopper to Yoco's payment page.
 */

require __DIR__ . '/lib.php';

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	bv_fail( 'Method not allowed.', 405 );
}
bv_require_same_site();

$input = bv_input();
if ( ! empty( $input['website'] ) ) {
	bv_fail( 'Invalid request.', 400 ); // Spam trap.
}
if ( bv_rate_limited( 'checkout', bv_client_ip(), 20, 10 ) ) {
	bv_fail( 'Too many attempts. Please wait a few minutes and try again.', 429 );
}
bv_record_attempt( 'checkout', bv_client_ip() );

// Customer details ----------------------------------------------------------------------------

$raw      = isset( $input['customer'] ) && is_array( $input['customer'] ) ? $input['customer'] : array();
$customer = array(
	'firstName' => bv_clean( $raw, 'firstName', 60 ),
	'lastName'  => bv_clean( $raw, 'lastName', 60 ),
	'email'     => strtolower( bv_clean( $raw, 'email', 120 ) ),
	'phone'     => bv_phone( bv_clean( $raw, 'phone', 20 ) ),
	'notes'     => bv_clean( $raw, 'notes', 500 ),
);
list( $address, $errors ) = bv_address_fields( $raw, true );
$customer                 = array_merge( $customer, $address );

if ( '' === $customer['firstName'] ) {
	$errors['firstName'] = 'Please enter your first name.';
}
if ( '' === $customer['lastName'] ) {
	$errors['lastName'] = 'Please enter your last name.';
}
if ( ! filter_var( $customer['email'], FILTER_VALIDATE_EMAIL ) ) {
	$errors['email'] = 'Please enter a valid email address.';
}
if ( '' === $customer['phone'] ) {
	$errors['phone'] = 'Please enter a 10-digit South African phone number, for example 082 123 4567.';
}

// Account: signed in, or create one now (optional) ----------------------------------------------

$account    = bv_current_customer();
$create     = ! $account && ! empty( $input['createAccount'] );
$password   = isset( $input['password'] ) && is_string( $input['password'] ) ? $input['password'] : '';
if ( $create ) {
	if ( strlen( $password ) < 8 ) {
		$errors['password'] = 'Choose a password of at least 8 characters.';
	} elseif ( empty( $errors['email'] ) && bv_customer_by_email( $customer['email'] ) ) {
		$errors['email'] = 'There is already an account with this email. Log in, or untick "Create an account" to check out as a guest.';
	}
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

if ( $create ) {
	$account = bv_create_customer( array_merge( $customer, array( 'password' => $password, 'marketing' => ! empty( $input['marketing'] ) ) ) );
	bv_login_customer( $account );
	bv_email_welcome( $account );
} elseif ( $account && '' === $account['address1'] ) {
	// First order from an account: save the address for next time.
	bv_update( 'customers', array_merge( $address, array( 'phone' => $customer['phone'] ) ), 'id = ?', array( $account['id'] ) );
}

// Order + Yoco checkout ---------------------------------------------------------------------

$id    = bv_new_order_id();
$token = bin2hex( random_bytes( 16 ) );
$order = bv_create_order(
	array(
		'id'          => $id,
		'token'       => $token,
		'customer_id' => $account ? (int) $account['id'] : null,
		'customer'    => $customer,
		'lines'       => $priced['lines'],
		'subtotal'    => $priced['subtotal'],
		'delivery'    => $priced['delivery'],
		'total'       => $priced['total'],
	)
);

list( $status, $body ) = bv_yoco_request(
	'POST',
	'/checkouts',
	array(
		'amount'     => $order['total'],
		'currency'   => 'ZAR',
		'successUrl' => bv_site_url( "thank-you.html?order=$id&t=$token" ),
		'cancelUrl'  => bv_site_url( 'checkout.html?cancelled=1' ),
		'failureUrl' => bv_site_url( 'checkout.html?failed=1' ),
		'metadata'   => array(
			'orderId' => $id,
			'email'   => $customer['email'],
		),
	),
	$id
);

if ( $status < 200 || $status >= 300 || empty( $body['id'] ) || empty( $body['redirectUrl'] ) ) {
	bv_update_order( $id, array( 'status' => 'checkout_failed' ) );
	bv_order_event( $id, 'checkout_failed', "Yoco checkout failed (HTTP $status): " . mb_substr( json_encode( $body ), 0, 500 ), false );
	error_log( "BabyVibes: Yoco checkout failed for $id (HTTP $status): " . json_encode( $body ) );
	bv_json( array( 'error' => 'We could not open the payment page. Please try again in a minute, or WhatsApp us.' ), 502 );
}

bv_update_order( $id, array( 'checkout_id' => $body['id'] ) );

bv_json( array( 'redirectUrl' => $body['redirectUrl'], 'order' => $id ) );
