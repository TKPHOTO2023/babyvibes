<?php
/** Admin sign-in. */

require __DIR__ . '/_inc.php';

$error = '';
$next  = (string) ( $_GET['next'] ?? $_POST['next'] ?? 'index.php' );
// Only allow returning to a page inside the admin.
if ( ! preg_match( '#^(/[\w./-]*admin/)?[\w-]+\.php(\?[\w=&%.-]*)?$#', $next ) ) {
	$next = 'index.php';
}

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	bv_check_csrf();
	$email = strtolower( trim( (string) ( $_POST['email'] ?? '' ) ) );
	$pass  = (string) ( $_POST['password'] ?? '' );
	if ( bv_rate_limited( 'admin', $email, 5, 15 ) || bv_rate_limited( 'admin-ip', bv_client_ip(), 20, 15 ) ) {
		$error = 'Too many attempts. Wait 15 minutes and try again.';
	} else {
		$a = bv_row( 'SELECT * FROM admins WHERE email = ?', array( $email ) );
		if ( $a && password_verify( $pass, $a['password_hash'] ) ) {
			session_regenerate_id( true );
			$_SESSION['admin_id']   = (int) $a['id'];
			$_SESSION['admin_seen'] = time();
			unset( $_SESSION['csrf'] );
			bv_update( 'admins', array( 'last_login_at' => bv_now() ), 'id = ?', array( $a['id'] ) );
			bv_redirect( $next );
		}
		bv_record_attempt( 'admin', $email );
		bv_record_attempt( 'admin-ip', bv_client_ip() );
		$error = 'That email and password don\'t match.';
	}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in | BabyVibes admin</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="admin.css"></head>
<body class="login"><main class="login-box">
<img src="../assets/img/logo.png" alt="BabyVibes" height="52">
<h1>Admin sign in</h1>
<?php if ( $error ) : ?><p class="flash flash-error" role="alert"><?php echo h( $error ); ?></p><?php endif; ?>
<form method="post" class="stack">
<?php echo bv_csrf_field(); ?><input type="hidden" name="next" value="<?php echo h( $next ); ?>">
<label>Email<input name="email" type="email" required autocomplete="username" autofocus></label>
<label>Password<input name="password" type="password" required autocomplete="current-password"></label>
<button class="btn">Sign in</button>
</form>
</main></body></html>
