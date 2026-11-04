<?php
/**
 * One-time setup: creates the database tables and the first admin login.
 * Safe to run again: it only adds what's missing and never deletes data.
 *
 * In the browser: https://your-shop/api/install.php?token=<admin_token from config.php>
 * From a terminal: php api/install.php --email=you@example.com --password='a long password'
 */

define( 'BV_HTML', true );
require __DIR__ . '/lib.php';

function bv_schema() {
	$mysql = 'mysql' === bv_db()->getAttribute( PDO::ATTR_DRIVER_NAME );
	$id    = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
	$end   = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
	$s     = 'VARCHAR(191)';
	return array(
		"CREATE TABLE IF NOT EXISTS customers (
			id $id,
			email $s NOT NULL UNIQUE,
			password_hash VARCHAR(255) NOT NULL,
			first_name VARCHAR(60) NOT NULL DEFAULT '',
			last_name VARCHAR(60) NOT NULL DEFAULT '',
			phone VARCHAR(20) NOT NULL DEFAULT '',
			address1 VARCHAR(120) NOT NULL DEFAULT '',
			address2 VARCHAR(120) NOT NULL DEFAULT '',
			suburb VARCHAR(80) NOT NULL DEFAULT '',
			city VARCHAR(80) NOT NULL DEFAULT '',
			province VARCHAR(40) NOT NULL DEFAULT '',
			postcode VARCHAR(4) NOT NULL DEFAULT '',
			marketing_opt_in SMALLINT NOT NULL DEFAULT 0,
			reset_hash VARCHAR(64) NULL,
			reset_expires DATETIME NULL,
			created_at DATETIME NOT NULL,
			last_login_at DATETIME NULL
		)$end",
		"CREATE TABLE IF NOT EXISTS orders (
			id VARCHAR(20) NOT NULL PRIMARY KEY,
			token VARCHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL,
			customer_id INT NULL,
			email $s NOT NULL,
			first_name VARCHAR(60) NOT NULL,
			last_name VARCHAR(60) NOT NULL,
			phone VARCHAR(20) NOT NULL,
			address1 VARCHAR(120) NOT NULL,
			address2 VARCHAR(120) NOT NULL DEFAULT '',
			suburb VARCHAR(80) NOT NULL,
			city VARCHAR(80) NOT NULL,
			province VARCHAR(40) NOT NULL,
			postcode VARCHAR(4) NOT NULL,
			notes TEXT NULL,
			subtotal INT NOT NULL,
			delivery INT NOT NULL,
			total INT NOT NULL,
			currency VARCHAR(3) NOT NULL DEFAULT 'ZAR',
			checkout_id VARCHAR(64) NULL,
			payment_id VARCHAR(64) NULL,
			payment_amount INT NULL,
			payment_mode VARCHAR(10) NULL,
			courier VARCHAR(60) NULL,
			tracking_number VARCHAR(80) NULL,
			tracking_url VARCHAR(255) NULL,
			admin_note TEXT NULL,
			created_at DATETIME NOT NULL,
			paid_at DATETIME NULL,
			shipped_at DATETIME NULL,
			delivered_at DATETIME NULL,
			cancelled_at DATETIME NULL,
			updated_at DATETIME NOT NULL
		)$end",
		'CREATE INDEX ' . ( $mysql ? '' : 'IF NOT EXISTS ' ) . 'orders_checkout ON orders (checkout_id)',
		'CREATE INDEX ' . ( $mysql ? '' : 'IF NOT EXISTS ' ) . 'orders_customer ON orders (customer_id)',
		'CREATE INDEX ' . ( $mysql ? '' : 'IF NOT EXISTS ' ) . 'orders_status_created ON orders (status, created_at)',
		"CREATE TABLE IF NOT EXISTS order_items (
			id $id,
			order_id VARCHAR(20) NOT NULL,
			slug VARCHAR(120) NOT NULL,
			name VARCHAR(255) NOT NULL,
			option_value VARCHAR(80) NOT NULL DEFAULT '',
			qty INT NOT NULL,
			unit INT NOT NULL,
			total INT NOT NULL
		)$end",
		'CREATE INDEX ' . ( $mysql ? '' : 'IF NOT EXISTS ' ) . 'items_order ON order_items (order_id)',
		"CREATE TABLE IF NOT EXISTS order_events (
			id $id,
			order_id VARCHAR(20) NOT NULL,
			type VARCHAR(30) NOT NULL,
			message TEXT NOT NULL,
			public SMALLINT NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL
		)$end",
		'CREATE INDEX ' . ( $mysql ? '' : 'IF NOT EXISTS ' ) . 'events_order ON order_events (order_id)',
		"CREATE TABLE IF NOT EXISTS reviews (
			id $id,
			order_id VARCHAR(20) NOT NULL UNIQUE,
			name VARCHAR(40) NOT NULL,
			location VARCHAR(40) NOT NULL DEFAULT '',
			rating SMALLINT NOT NULL,
			professionalism SMALLINT NOT NULL DEFAULT 0,
			convenience SMALLINT NOT NULL DEFAULT 0,
			text TEXT NOT NULL,
			products TEXT NULL,
			status VARCHAR(10) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			approved_at DATETIME NULL
		)$end",
		"CREATE TABLE IF NOT EXISTS emails (
			id $id,
			to_email $s NOT NULL,
			template VARCHAR(40) NOT NULL,
			subject VARCHAR(255) NOT NULL,
			status VARCHAR(10) NOT NULL,
			message_id VARCHAR(191) NOT NULL DEFAULT '',
			error TEXT NULL,
			order_id VARCHAR(20) NULL,
			created_at DATETIME NOT NULL
		)$end",
		"CREATE TABLE IF NOT EXISTS admins (
			id $id,
			email $s NOT NULL UNIQUE,
			name VARCHAR(60) NOT NULL DEFAULT '',
			password_hash VARCHAR(255) NOT NULL,
			created_at DATETIME NOT NULL,
			last_login_at DATETIME NULL
		)$end",
		"CREATE TABLE IF NOT EXISTS attempts (
			id $id,
			kind VARCHAR(20) NOT NULL,
			key_hash VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL
		)$end",
		'CREATE INDEX ' . ( $mysql ? '' : 'IF NOT EXISTS ' ) . 'attempts_lookup ON attempts (kind, key_hash, created_at)',
		"CREATE TABLE IF NOT EXISTS settings (
			k VARCHAR(64) NOT NULL PRIMARY KEY,
			v TEXT NULL
		)$end",
	);
}

function bv_install() {
	$done = array();
	foreach ( bv_schema() as $sql ) {
		try {
			bv_db()->exec( $sql );
		} catch ( PDOException $e ) {
			// MySQL has no "CREATE INDEX IF NOT EXISTS": an existing index (error 1061) is fine.
			if ( false === strpos( $e->getMessage(), '1061' ) ) {
				throw $e;
			}
		}
		if ( preg_match( '/CREATE (TABLE|INDEX)( IF NOT EXISTS)? (\w+)/', $sql, $m ) ) {
			$done[] = strtolower( $m[1] ) . ' ' . $m[3];
		}
	}
	return $done;
}

function bv_add_admin( $email, $password, $name = '' ) {
	$email = strtolower( trim( $email ) );
	if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		return 'That email address is not valid.';
	}
	if ( strlen( $password ) < 12 ) {
		return 'Use an admin password of at least 12 characters.';
	}
	if ( bv_row( 'SELECT id FROM admins WHERE email = ?', array( $email ) ) ) {
		bv_update( 'admins', array( 'password_hash' => password_hash( $password, PASSWORD_DEFAULT ) ), 'email = ?', array( $email ) );
		return "Password updated for admin $email.";
	}
	bv_insert( 'admins', array( 'email' => $email, 'name' => $name, 'password_hash' => password_hash( $password, PASSWORD_DEFAULT ), 'created_at' => bv_now() ) );
	return "Admin $email created.";
}

// Terminal --------------------------------------------------------------------------------------
if ( 'cli' === PHP_SAPI ) {
	$args = getopt( '', array( 'email::', 'password::', 'name::' ) );
	foreach ( bv_install() as $line ) {
		echo "ok  $line\n";
	}
	if ( ! empty( $args['email'] ) ) {
		echo bv_add_admin( $args['email'], (string) ( $args['password'] ?? '' ), (string) ( $args['name'] ?? '' ) ) . "\n";
	}
	exit( 0 );
}

// Browser ---------------------------------------------------------------------------------------
$config = bv_config();
$token  = (string) ( $_REQUEST['token'] ?? '' );
if ( strlen( $config['admin_token'] ) < 16 || 'change-me-to-a-long-random-string' === $config['admin_token'] || ! hash_equals( $config['admin_token'], $token ) ) {
	http_response_code( 403 );
	exit( "Not allowed. Set a long admin_token in api/config.php and open this page with ?token=... added to the address.\n" );
}
header( 'Content-Type: text/html; charset=utf-8' );
header( 'X-Robots-Tag: noindex' );
$messages = array();
try {
	foreach ( bv_install() as $line ) {
		$messages[] = "ok: $line";
	}
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$messages[] = bv_add_admin( (string) ( $_POST['email'] ?? '' ), (string) ( $_POST['password'] ?? '' ), (string) ( $_POST['name'] ?? '' ) );
	}
} catch ( Exception $e ) {
	$messages[] = 'Database error: ' . $e->getMessage();
}
$admins = (int) bv_row( 'SELECT COUNT(*) AS n FROM admins' )['n'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BabyVibes setup</title>
<style>body{font:16px/1.6 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;color:#2E2433}input{font:inherit;padding:10px;width:100%;box-sizing:border-box;margin:4px 0 12px;border:2px solid #E7D0D7;border-radius:10px}button{font:inherit;font-weight:800;background:#D4196C;color:#fff;border:0;border-radius:999px;padding:12px 26px;cursor:pointer}code{background:#FDF0F4;padding:2px 6px;border-radius:6px}li{font-family:ui-monospace,monospace;font-size:14px}</style></head>
<body>
<h1>BabyVibes setup</h1>
<p>The database tables are ready.</p>
<ul><?php foreach ( $messages as $m ) : ?><li><?php echo bv_e( $m ); ?></li><?php endforeach; ?></ul>
<h2><?php echo $admins ? 'Add another admin (or change a password)' : 'Create your admin login'; ?></h2>
<form method="post">
<input type="hidden" name="token" value="<?php echo bv_e( $token ); ?>">
<label>Your name<input name="name" autocomplete="name"></label>
<label>Email<input name="email" type="email" required autocomplete="username"></label>
<label>Password (at least 12 characters)<input name="password" type="password" required minlength="12" autocomplete="new-password"></label>
<button>Save admin</button>
</form>
<?php if ( $admins ) : ?><p>Then sign in at <a href="../admin/">/admin</a>.</p><?php endif; ?>
</body></html>
