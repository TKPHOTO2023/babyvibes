<?php
/**
 * Admin: System check. Tests every setting and connection the shop needs (server, database,
 * Yoco, Brevo, security) and can send a test email. Run it after going live and after any change.
 */

require __DIR__ . '/_inc.php';
$admin = bv_require_admin();

$config = bv_config();
$checks = array(); // [section, status ok|warn|fail, title, detail]

function bv_check( $section, $status, $title, $detail = '' ) {
	global $checks;
	$checks[] = array( $section, $status, $title, $detail );
}

// Test email ------------------------------------------------------------------------------------
if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	bv_check_csrf();
	$to   = filter_var( trim( (string) ( $_POST['to'] ?? '' ) ), FILTER_VALIDATE_EMAIL ) ?: $admin['email'];
	$sent = bv_send_email(
		$to,
		'',
		'BabyVibes test email',
		bv_email_layout( 'Your BabyVibes emails are working', bv_eh( 'It works!' ) . bv_ep( 'This test email was sent from the BabyVibes admin System check through Brevo. If it arrived in your inbox (not spam), order emails will arrive too.' ) . bv_ebutton( 'Open the admin', bv_site_url( 'admin/' ) ) ),
		"It works! This test email was sent from the BabyVibes admin System check through Brevo.",
		'test'
	);
	$last = bv_row( "SELECT error FROM emails WHERE template = 'test' ORDER BY id DESC LIMIT 1" );
	bv_flash( $sent ? "Test email sent to $to. Check the inbox (and the spam folder)." : 'The test email failed: ' . ( $last ? $last['error'] : 'unknown error' ), $sent ? 'ok' : 'error' );
	bv_redirect( 'health.php' );
}

// Server ------------------------------------------------------------------------------------
$php = PHP_VERSION;
bv_check( 'Server', version_compare( $php, '8.1', '>=' ) ? 'ok' : ( version_compare( $php, '7.4', '>=' ) ? 'warn' : 'fail' ), "PHP $php", version_compare( $php, '8.1', '>=' ) ? '' : 'Choose PHP 8.1 or newer in cPanel > Select PHP Version (or MultiPHP Manager).' );
foreach ( array( 'pdo_mysql' => 'database', 'curl' => 'Yoco and Brevo', 'mbstring' => 'text handling', 'openssl' => 'secure connections', 'json' => 'data' ) as $ext => $why ) {
	bv_check( 'Server', extension_loaded( $ext ) ? 'ok' : 'fail', "PHP extension $ext", extension_loaded( $ext ) ? '' : "Needed for $why. Turn it on in cPanel > Select PHP Version > Extensions." );
}
$https = 0 === strpos( $config['site_url'], 'https://' );
bv_check( 'Server', $https ? 'ok' : 'fail', 'Shop address: ' . $config['site_url'], $https ? '' : 'site_url in api/config.php must start with https:// (Yoco needs it). Turn on SSL in cPanel > SSL/TLS Status.' );
bv_check( 'Server', bv_https() ? 'ok' : 'warn', bv_https() ? 'This page was opened over https' : 'This page was opened without https', bv_https() ? '' : 'Visitors should always use https. The .htaccess file in the shop folder redirects http to https.' );
$here = ( bv_https() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' );
if ( $https && $here && 0 !== strpos( rtrim( $config['site_url'], '/' ) . '/', $here . '/' ) ) {
	bv_check( 'Server', 'warn', 'site_url does not match the address you are using', "You opened the admin at $here, but site_url is {$config['site_url']}. Emails and Yoco use site_url." );
}

// Database ----------------------------------------------------------------------------------
try {
	$driver = bv_db()->getAttribute( PDO::ATTR_DRIVER_NAME );
	$ver    = bv_db()->getAttribute( PDO::ATTR_SERVER_VERSION );
	bv_check( 'Database', 'ok', "Connected ($driver $ver)" );
	$missing = array();
	foreach ( array( 'customers', 'orders', 'order_items', 'order_events', 'reviews', 'emails', 'admins', 'attempts', 'settings' ) as $t ) {
		try {
			bv_db()->query( "SELECT 1 FROM $t LIMIT 1" );
		} catch ( PDOException $e ) {
			$missing[] = $t;
		}
	}
	bv_check( 'Database', $missing ? 'fail' : 'ok', $missing ? 'Missing tables: ' . implode( ', ', $missing ) : 'All 9 tables are in place', $missing ? 'Open api/install.php?token=... to create them.' : '' );
	$counts = bv_row( "SELECT (SELECT COUNT(*) FROM orders) AS o, (SELECT COUNT(*) FROM customers) AS c, (SELECT COUNT(*) FROM admins) AS a" );
	bv_check( 'Database', 'ok', "{$counts['o']} orders, {$counts['c']} customer accounts, {$counts['a']} admin login" . ( 1 === (int) $counts['a'] ? '' : 's' ) );
} catch ( Exception $e ) {
	bv_check( 'Database', 'fail', 'Database error', $e->getMessage() );
}

// Yoco ----------------------------------------------------------------------------------------
$key  = (string) $config['yoco_secret_key'];
$mode = 0 === strpos( $key, 'sk_live_' ) ? 'live' : ( 0 === strpos( $key, 'sk_test_' ) ? 'test' : '' );
if ( ! $mode || false !== strpos( $key, 'REPLACE' ) ) {
	bv_check( 'Yoco payments', 'fail', 'Yoco secret key is not set', 'Put your secret key (sk_test_... or sk_live_...) in api/config.php. Find it in the Yoco portal under Sales > Payment gateway (or Developers > Keys).' );
} else {
	bv_check( 'Yoco payments', 'live' === $mode ? 'ok' : 'warn', 'Using the ' . strtoupper( $mode ) . ' key', 'live' === $mode ? 'Real payments are taken.' : 'Test mode: no real money is taken. Switch to the sk_live_ key when you launch, then run Connect Yoco again.' );
	list( $st, $res ) = bv_yoco_request( 'GET', '/webhooks' );
	if ( 200 === $st ) {
		bv_check( 'Yoco payments', 'ok', 'Yoco accepted the secret key' );
		$want  = bv_site_url( 'api/webhook.php' );
		$found = array_filter( (array) ( $res['subscriptions'] ?? array() ), function ( $s ) use ( $want ) { return isset( $s['url'] ) && rtrim( $s['url'], '/' ) === $want; } );
		$info  = json_decode( (string) bv_setting( 'yoco_webhook_info' ), true );
		if ( ! $found ) {
			bv_check( 'Yoco payments', 'fail', 'Payment confirmations are not connected', "Yoco has no webhook for $want, so paid orders would stay on Awaiting payment. Open api/setup-webhook.php?token=... once." );
		} elseif ( count( $found ) > 1 ) {
			bv_check( 'Yoco payments', 'warn', count( $found ) . ' webhooks registered for this shop', 'Open api/setup-webhook.php?token=... once to replace them with a single one.' );
		} elseif ( ! bv_webhook_secret() ) {
			bv_check( 'Yoco payments', 'fail', 'The webhook secret is missing', 'Open api/setup-webhook.php?token=... again so the shop can check Yoco\'s signatures.' );
		} else {
			$f = array_values( $found )[0];
			bv_check( 'Yoco payments', ( $f['mode'] ?? $mode ) === $mode ? 'ok' : 'warn', 'Payment confirmations are connected (' . ( $f['mode'] ?? '?' ) . ' mode)', ( $f['mode'] ?? $mode ) === $mode ? "Yoco notifies $want" : 'The webhook was registered with the other key. Open api/setup-webhook.php?token=... again.' );
		}
	} elseif ( 401 === $st || 403 === $st ) {
		bv_check( 'Yoco payments', 'fail', 'Yoco rejected the secret key', 'Check yoco_secret_key in api/config.php: copy the Secret key (not the Public key) from the Yoco portal.' );
	} else {
		bv_check( 'Yoco payments', 'fail', "Could not reach Yoco (HTTP $st)", (string) ( $res['message'] ?? '' ) . ' If this keeps happening, ask your host whether outgoing connections to payments.yoco.com are allowed.' );
	}
	$last = (string) bv_setting( 'last_webhook_at' );
	bv_check( 'Yoco payments', $last ? 'ok' : 'warn', $last ? 'Last notification from Yoco: ' . bv_local( substr( $last, 0, 19 ) ) . ' (' . substr( $last, 20 ) . ')' : 'No notification from Yoco has arrived yet', $last ? '' : 'Normal before the first payment. After a test payment, this should show a time.' );
}
$stuck = (int) bv_row( "SELECT COUNT(*) AS n FROM orders WHERE status = 'refund_pending' AND updated_at < ?", array( gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) )['n'];
if ( $stuck ) {
	bv_check( 'Yoco payments', 'warn', "$stuck refund(s) waiting more than an hour for Yoco", 'Check them in the Yoco portal.' );
}

// Brevo -------------------------------------------------------------------------------------------
if ( empty( $config['brevo_api_key'] ) || false !== strpos( $config['brevo_api_key'], 'REPLACE' ) ) {
	bv_check( 'Emails (Brevo)', defined( 'BV_MAIL_LOG' ) ? 'warn' : 'fail', 'Brevo API key is not set', 'Create one in Brevo > SMTP & API > API keys and put it in api/config.php. Without it, no emails are sent.' );
} else {
	list( $st, $acc ) = bv_brevo_request( 'GET', '/account' );
	if ( 200 === $st ) {
		$credits = '';
		foreach ( (array) ( $acc['plan'] ?? array() ) as $p ) {
			if ( isset( $p['credits'] ) ) {
				$credits = " ({$p['type']} plan, {$p['credits']} " . ( 'sendLimit' === ( $p['creditsType'] ?? '' ) ? 'emails a day' : 'credits' ) . ')';
			}
		}
		bv_check( 'Emails (Brevo)', 'ok', 'Brevo accepted the API key' . $credits, 'Account: ' . ( $acc['companyName'] ?? '' ) );
		$from = strtolower( (string) $config['from_email'] );
		list( , $snd ) = bv_brevo_request( 'GET', '/senders' );
		$sender = array_filter( (array) ( $snd['senders'] ?? array() ), function ( $s ) use ( $from ) { return strtolower( $s['email'] ?? '' ) === $from; } );
		list( , $dom ) = bv_brevo_request( 'GET', '/senders/domains' );
		$domain = substr( strrchr( $from, '@' ), 1 );
		$d      = array_values( array_filter( (array) ( $dom['domains'] ?? array() ), function ( $x ) use ( $domain ) { return strtolower( $x['domain_name'] ?? '' ) === $domain; } ) );
		if ( $d && ! empty( $d[0]['authenticated'] ) ) {
			bv_check( 'Emails (Brevo)', 'ok', "Domain $domain is authenticated in Brevo", 'Emails are signed (DKIM), so they reach inboxes.' );
		} else {
			bv_check( 'Emails (Brevo)', 'warn', "Domain $domain is not authenticated in Brevo", 'Brevo > Senders, domains & dedicated IPs > Domains: add the DNS records Brevo shows in cPanel > Zone Editor, then click Authenticate. Without this, emails often go to spam.' );
		}
		$active = $sender && array_values( $sender )[0]['active'];
		bv_check( 'Emails (Brevo)', $active || ( $d && ! empty( $d[0]['verified'] ) ) ? 'ok' : 'fail', $active ? "Sender $from is set up in Brevo" : "Sender $from is not an active Brevo sender", $active ? '' : 'Add it in Brevo > Senders, domains & dedicated IPs > Senders, or authenticate its domain. Brevo refuses to send from unknown addresses.' );
	} elseif ( 401 === $st ) {
		bv_check( 'Emails (Brevo)', 'fail', 'Brevo rejected the API key', 'Check brevo_api_key in api/config.php (it starts with xkeysib-). If Brevo shows "unrecognised IP address", go to Brevo > Security > Authorised IPs and either add your hosting server\'s IP address or turn the IP restriction off.' );
	} else {
		bv_check( 'Emails (Brevo)', 'fail', "Could not reach Brevo (HTTP $st)", 'Ask your host whether outgoing connections to api.brevo.com are allowed.' );
	}
}
$failed = bv_rows( "SELECT template, error, created_at FROM emails WHERE status = 'failed' AND created_at > ? ORDER BY id DESC LIMIT 3", array( gmdate( 'Y-m-d H:i:s', time() - 7 * 86400 ) ) );
bv_check( 'Emails (Brevo)', $failed ? 'warn' : 'ok', $failed ? count( $failed ) . ' email(s) failed this week' : 'No failed emails this week', $failed ? 'Latest error: ' . $failed[0]['error'] : '' );
$shop = bv_shop();
bv_check( 'Emails (Brevo)', '27000000000' === (string) $shop['whatsapp'] ? 'warn' : 'ok', '27000000000' === (string) $shop['whatsapp'] ? 'WhatsApp number is still the placeholder' : 'Shop contact details set', 'Shown in every email and page footer. Set email and whatsapp in data/shop.json and rebuild the pages.' );

// Security ----------------------------------------------------------------------------------
$tok = (string) $config['admin_token'];
bv_check( 'Security', strlen( $tok ) >= 16 && 'change-me-to-a-long-random-string' !== $tok ? 'ok' : 'fail', 'Setup password (admin_token)', strlen( $tok ) >= 16 && 'change-me-to-a-long-random-string' !== $tok ? '' : 'Set a long random admin_token in api/config.php.' );
foreach ( array( 'tests' => 'test kit', 'tools' => 'page builder' ) as $dir => $what ) {
	if ( is_dir( bv_root() . '/' . $dir ) && ! defined( 'BV_MAIL_LOG' ) ) {
		bv_check( 'Security', 'warn', "The $dir/ folder is on the server", "The $what isn't needed on the live site. Delete the $dir folder in cPanel > File Manager (the .htaccess file blocks it in the meantime)." );
	}
}
bv_check( 'Security', is_file( __DIR__ . '/../api/install.php' ) ? 'warn' : 'ok', is_file( __DIR__ . '/../api/install.php' ) ? 'api/install.php is still on the server' : 'api/install.php has been removed', is_file( __DIR__ . '/../api/install.php' ) ? 'It is protected by your admin_token, but once setup is done you can rename or delete it.' : '' );
$cfg = __DIR__ . '/../api/config.php';
if ( is_file( $cfg ) && ( fileperms( $cfg ) & 0004 ) ) {
	bv_check( 'Security', 'warn', 'api/config.php can be read by other users on the server', 'Set its permissions to 600 or 640 in cPanel > File Manager > Permissions. (The web can\'t read it either way.)' );
}

bv_admin_header( 'System check', 'health.php' );
$bad  = count( array_filter( $checks, function ( $c ) { return 'fail' === $c[1]; } ) );
$warn = count( array_filter( $checks, function ( $c ) { return 'warn' === $c[1]; } ) );
?>
<div class="page-head"><h1>System check</h1><a class="btn btn-ghost" href="health.php">Run again</a></div>
<p class="health-sum health-<?php echo $bad ? 'fail' : ( $warn ? 'warn' : 'ok' ); ?>" role="status">
	<?php echo $bad ? "$bad problem" . ( 1 === $bad ? '' : 's' ) . ' to fix before taking orders' . ( $warn ? ", and $warn thing" . ( 1 === $warn ? '' : 's' ) . ' to check' : '' ) . '.' : ( $warn ? "Everything essential works. $warn thing" . ( 1 === $warn ? '' : 's' ) . ' to check.' : 'Everything is set up and working.' ); ?>
</p>
<?php
$sections = array();
foreach ( $checks as $c ) {
	$sections[ $c[0] ][] = $c;
}
foreach ( $sections as $name => $rows ) :
	?>
	<section class="card"><h2><?php echo h( $name ); ?></h2><ul class="checks">
	<?php foreach ( $rows as $c ) : ?>
		<li class="chk-<?php echo h( $c[1] ); ?>"><span class="chk-icon" aria-hidden="true"><?php echo 'ok' === $c[1] ? '&#10003;' : ( 'warn' === $c[1] ? '!' : '&#10005;' ); ?></span>
		<span><strong><span class="sr"><?php echo array( 'ok' => 'OK: ', 'warn' => 'Check: ', 'fail' => 'Problem: ' )[ $c[1] ]; ?></span><?php echo h( $c[2] ); ?></strong><?php echo $c[3] ? '<br><span class="muted small">' . h( $c[3] ) . '</span>' : ''; ?></span></li>
	<?php endforeach; ?>
	</ul></section>
<?php endforeach; ?>
<section class="card">
	<h2>Send a test email</h2>
	<form method="post" class="inline"><?php echo bv_csrf_field(); ?>
		<input type="email" name="to" value="<?php echo h( $admin['email'] ); ?>" aria-label="Send the test email to">
		<button class="btn">Send test email</button>
	</form>
</section>
<?php
bv_admin_footer();
