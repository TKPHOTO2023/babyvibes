<?php
/**
 * Customer accounts. All calls are JSON; changes are POST and must come from the shop's pages.
 *
 * GET  ?action=me        signed-in customer (or null)
 * GET  ?action=orders    the signed-in customer's orders with tracking
 * POST ?action=register  { firstName, lastName, email, password, marketing }
 * POST ?action=login     { email, password }
 * POST ?action=logout
 * POST ?action=update    { firstName, lastName, phone, address..., marketing, currentPassword?, newPassword? }
 * POST ?action=forgot    { email }            emails a reset link (always answers "ok")
 * POST ?action=reset     { token, password }
 */

require __DIR__ . '/lib.php';

$action = (string) ( $_GET['action'] ?? '' );
$post   = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
if ( $post ) {
	bv_require_same_site();
}
$in = $post ? bv_input() : array();

switch ( $action ) {
	case 'me':
		$c = bv_current_customer();
		bv_json( array( 'customer' => $c ? bv_public_customer( $c ) : null ) );

	case 'orders':
		$c = bv_current_customer() ?: bv_fail( 'Please log in.', 401 );
		$rows = bv_rows( "SELECT id FROM orders WHERE customer_id = ? AND status NOT IN ('checkout_failed') ORDER BY created_at DESC LIMIT 100", array( $c['id'] ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$o = bv_load_order( $r['id'] );
			if ( 'pending' === $o['status'] && strtotime( $o['created'] . ' UTC' ) < time() - 86400 ) {
				continue; // Abandoned payment attempts older than a day.
			}
			$out[] = bv_public_order( $o, true );
		}
		bv_json( array( 'orders' => $out ) );

	case 'register':
		$post || bv_fail( 'Method not allowed.', 405 );
		if ( ! empty( $in['website'] ) ) {
			bv_fail( 'Invalid request.', 400 );
		}
		if ( bv_rate_limited( 'register', bv_client_ip(), 10, 60 ) ) {
			bv_fail( 'Too many attempts. Please try again later.', 429 );
		}
		bv_record_attempt( 'register', bv_client_ip() );
		$d = array(
			'firstName' => bv_clean( $in, 'firstName', 60 ),
			'lastName'  => bv_clean( $in, 'lastName', 60 ),
			'email'     => strtolower( bv_clean( $in, 'email', 120 ) ),
			'password'  => isset( $in['password'] ) && is_string( $in['password'] ) ? $in['password'] : '',
			'marketing' => ! empty( $in['marketing'] ),
		);
		$e = array();
		if ( '' === $d['firstName'] ) {
			$e['firstName'] = 'Please enter your first name.';
		}
		if ( '' === $d['lastName'] ) {
			$e['lastName'] = 'Please enter your last name.';
		}
		if ( ! filter_var( $d['email'], FILTER_VALIDATE_EMAIL ) ) {
			$e['email'] = 'Please enter a valid email address.';
		} elseif ( bv_customer_by_email( $d['email'] ) ) {
			$e['email'] = 'There is already an account with this email. Log in, or reset your password.';
		}
		if ( strlen( $d['password'] ) < 8 ) {
			$e['password'] = 'Choose a password of at least 8 characters.';
		}
		if ( $e ) {
			bv_json( array( 'error' => 'Please check the highlighted details.', 'fields' => $e ), 422 );
		}
		$c = bv_create_customer( $d );
		bv_login_customer( $c );
		bv_email_welcome( $c );
		bv_json( array( 'customer' => bv_public_customer( $c ) ) );

	case 'login':
		$post || bv_fail( 'Method not allowed.', 405 );
		$email = strtolower( bv_clean( $in, 'email', 120 ) );
		$pass  = isset( $in['password'] ) && is_string( $in['password'] ) ? $in['password'] : '';
		if ( bv_rate_limited( 'login', $email, 6, 15 ) || bv_rate_limited( 'login-ip', bv_client_ip(), 30, 15 ) ) {
			bv_fail( 'Too many login attempts. Please wait 15 minutes, or reset your password.', 429 );
		}
		$c = bv_customer_by_email( $email );
		if ( ! $c || ! password_verify( $pass, $c['password_hash'] ) ) {
			bv_record_attempt( 'login', $email );
			bv_record_attempt( 'login-ip', bv_client_ip() );
			bv_fail( 'That email and password don\'t match. Try again, or reset your password.', 401 );
		}
		if ( password_needs_rehash( $c['password_hash'], PASSWORD_DEFAULT ) ) {
			bv_update( 'customers', array( 'password_hash' => password_hash( $pass, PASSWORD_DEFAULT ) ), 'id = ?', array( $c['id'] ) );
		}
		bv_login_customer( $c );
		bv_json( array( 'customer' => bv_public_customer( $c ) ) );

	case 'logout':
		$post || bv_fail( 'Method not allowed.', 405 );
		bv_session();
		$_SESSION = array();
		session_destroy();
		setcookie( 'bv_session', '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => bv_https(), 'httponly' => true, 'samesite' => 'Lax' ) );
		bv_json( array( 'ok' => true ) );

	case 'update':
		$post || bv_fail( 'Method not allowed.', 405 );
		$c = bv_current_customer() ?: bv_fail( 'Please log in.', 401 );
		list( $addr, $e ) = bv_address_fields( $in, false );
		$d = array_merge(
			array(
				'first_name'       => bv_clean( $in, 'firstName', 60 ),
				'last_name'        => bv_clean( $in, 'lastName', 60 ),
				'phone'            => '',
				'marketing_opt_in' => ! empty( $in['marketing'] ) ? 1 : 0,
			),
			$addr
		);
		$phone = bv_clean( $in, 'phone', 20 );
		if ( '' !== $phone ) {
			$d['phone'] = bv_phone( $phone );
			if ( '' === $d['phone'] ) {
				$e['phone'] = 'Please enter a 10-digit South African phone number.';
			}
		}
		if ( '' === $d['first_name'] ) {
			$e['firstName'] = 'Please enter your first name.';
		}
		if ( '' === $d['last_name'] ) {
			$e['lastName'] = 'Please enter your last name.';
		}
		$new = isset( $in['newPassword'] ) && is_string( $in['newPassword'] ) ? $in['newPassword'] : '';
		if ( '' !== $new ) {
			$cur = isset( $in['currentPassword'] ) && is_string( $in['currentPassword'] ) ? $in['currentPassword'] : '';
			if ( ! password_verify( $cur, $c['password_hash'] ) ) {
				$e['currentPassword'] = 'Your current password is not right.';
			} elseif ( strlen( $new ) < 8 ) {
				$e['newPassword'] = 'Choose a password of at least 8 characters.';
			} else {
				$d['password_hash'] = password_hash( $new, PASSWORD_DEFAULT );
			}
		}
		if ( $e ) {
			bv_json( array( 'error' => 'Please check the highlighted details.', 'fields' => $e ), 422 );
		}
		bv_update( 'customers', $d, 'id = ?', array( $c['id'] ) );
		if ( isset( $d['password_hash'] ) ) {
			bv_session();
			session_regenerate_id( true );
		}
		bv_json( array( 'customer' => bv_public_customer( bv_customer( $c['id'] ) ) ) );

	case 'forgot':
		$post || bv_fail( 'Method not allowed.', 405 );
		$email = strtolower( bv_clean( $in, 'email', 120 ) );
		if ( bv_rate_limited( 'forgot', $email, 3, 60 ) || bv_rate_limited( 'forgot-ip', bv_client_ip(), 10, 60 ) ) {
			bv_fail( 'Too many requests. Please check your inbox (and spam folder) or try again later.', 429 );
		}
		bv_record_attempt( 'forgot', $email );
		bv_record_attempt( 'forgot-ip', bv_client_ip() );
		$c = bv_customer_by_email( $email );
		if ( $c ) {
			$token = bin2hex( random_bytes( 32 ) );
			bv_update( 'customers', array( 'reset_hash' => hash( 'sha256', $token ), 'reset_expires' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), 'id = ?', array( $c['id'] ) );
			bv_email_password_reset( $c, bv_site_url( 'reset-password.html?token=' . $token ) );
		}
		// Same answer either way, so nobody can find out who has an account.
		bv_json( array( 'ok' => true ) );

	case 'reset':
		$post || bv_fail( 'Method not allowed.', 405 );
		$token = bv_clean( $in, 'token', 64 );
		$pass  = isset( $in['password'] ) && is_string( $in['password'] ) ? $in['password'] : '';
		if ( bv_rate_limited( 'reset-ip', bv_client_ip(), 20, 60 ) ) {
			bv_fail( 'Too many attempts. Please try again later.', 429 );
		}
		bv_record_attempt( 'reset-ip', bv_client_ip() );
		$c = '' !== $token ? bv_row( 'SELECT * FROM customers WHERE reset_hash = ? AND reset_expires > ?', array( hash( 'sha256', $token ), bv_now() ) ) : null;
		if ( ! $c ) {
			bv_fail( 'This reset link has expired or was already used. Please ask for a new one.', 400 );
		}
		if ( strlen( $pass ) < 8 ) {
			bv_json( array( 'error' => 'Please check the highlighted details.', 'fields' => array( 'password' => 'Choose a password of at least 8 characters.' ) ), 422 );
		}
		bv_update( 'customers', array( 'password_hash' => password_hash( $pass, PASSWORD_DEFAULT ), 'reset_hash' => null, 'reset_expires' => null ), 'id = ?', array( $c['id'] ) );
		bv_login_customer( $c );
		bv_json( array( 'customer' => bv_public_customer( bv_customer( $c['id'] ) ) ) );

	default:
		bv_fail( 'Unknown action.', 404 );
}
