<?php
/**
 * BabyVibes emails: sending through Brevo, and every automatic email the shop sends.
 * All emails share one branded layout (logo, pink buttons) and have a plain-text version.
 */

defined( 'BV_YOCO_API' ) || exit;

/**
 * Sends one email through Brevo's transactional API and logs it in the `emails` table.
 * Locally (tests), BV_MAIL_LOG writes emails to a file instead.
 */
function bv_send_email( $to, $to_name, $subject, $html, $text, $template, $order_id = null ) {
	$config = bv_config();
	$status = 'sent';
	$error  = '';
	$msg_id = '';

	if ( defined( 'BV_MAIL_LOG' ) ) {
		file_put_contents( BV_MAIL_LOG, "To: $to\nSubject: $subject\nTemplate: $template\n\n$text\n-----\n", FILE_APPEND );
		if ( defined( 'BV_MAIL_HTML_DIR' ) ) {
			file_put_contents( BV_MAIL_HTML_DIR . '/' . $template . '.html', $html );
		}
	} elseif ( empty( $config['brevo_api_key'] ) ) {
		$status = 'failed';
		$error  = 'brevo_api_key is not set in api/config.php';
	} else {
		$payload = array(
			'sender'      => array( 'name' => $config['from_name'], 'email' => $config['from_email'] ),
			'to'          => array( array_filter( array( 'email' => $to, 'name' => $to_name ) ) ),
			'replyTo'     => array( 'email' => bv_shop()['email'], 'name' => $config['from_name'] ),
			'subject'     => $subject,
			'htmlContent' => $html,
			'textContent' => $text,
			'tags'        => array( $template ),
		);
		$ch = curl_init( 'https://api.brevo.com/v3/smtp/email' );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => json_encode( $payload ),
				CURLOPT_HTTPHEADER     => array( 'api-key: ' . $config['brevo_api_key'], 'Content-Type: application/json', 'Accept: application/json' ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 15,
			)
		);
		$raw  = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$cerr = curl_error( $ch );
		curl_close( $ch );
		$res = json_decode( (string) $raw, true );
		if ( $code >= 200 && $code < 300 ) {
			$msg_id = isset( $res['messageId'] ) ? $res['messageId'] : '';
		} else {
			$status = 'failed';
			$error  = $cerr ? $cerr : "HTTP $code " . mb_substr( (string) $raw, 0, 300 );
			error_log( "BabyVibes: email '$template' to $to failed: $error" );
		}
	}

	try {
		bv_insert(
			'emails',
			array(
				'to_email'   => $to,
				'template'   => $template,
				'subject'    => $subject,
				'status'     => $status,
				'message_id' => $msg_id,
				'error'      => $error,
				'order_id'   => $order_id,
				'created_at' => bv_now(),
			)
		);
	} catch ( Exception $e ) {
		error_log( 'BabyVibes: could not log email: ' . $e->getMessage() );
	}
	return 'sent' === $status;
}

// Layout ------------------------------------------------------------------------------------

function bv_e( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

/**
 * Wraps email content in the BabyVibes layout. $blocks is HTML built with the helpers below.
 * Inline styles and tables only, so it looks right in Gmail, Outlook and phone mail apps.
 */
function bv_email_layout( $preheader, $blocks ) {
	$shop = bv_shop();
	$logo = bv_e( bv_site_url( 'assets/img/logo.png' ) );
	$home = bv_e( bv_site_url( '' ) );
	$wa   = bv_e( 'https://wa.me/' . $shop['whatsapp'] );
	$mail = bv_e( $shop['email'] );
	$year = gmdate( 'Y' );
	return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<meta name="color-scheme" content="light"><title>BabyVibes</title></head>'
		. '<body style="margin:0;padding:0;background:#FDF0F4;">'
		. '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . bv_e( $preheader ) . '</div>'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FDF0F4;"><tr><td align="center" style="padding:24px 12px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">'
		. '<tr><td align="center" style="padding:8px 0 20px;"><a href="' . $home . '"><img src="' . $logo . '" alt="BabyVibes" width="180" style="display:block;width:180px;height:auto;border:0;"></a></td></tr>'
		. '<tr><td style="background:#FFFFFF;border-radius:20px;padding:36px 32px;font-family:Nunito,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#2E2433;">'
		. $blocks
		. '</td></tr>'
		. '<tr><td align="center" style="padding:24px 16px;font-family:Nunito,Segoe UI,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#5F5462;">'
		. 'Questions? <a href="' . $wa . '" style="color:#B8155D;">WhatsApp us</a> or reply to this email (' . $mail . ').<br>'
		. '<a href="' . $home . '" style="color:#B8155D;">BabyVibes</a> is trading as Hostdigi (Pty) Ltd. &copy; ' . $year
		. '</td></tr></table></td></tr></table></body></html>';
}

function bv_eh( $text ) {
	return '<h1 style="margin:0 0 12px;font-family:Fredoka,Nunito,Segoe UI,Helvetica,Arial,sans-serif;font-size:26px;line-height:1.25;color:#2E2433;">' . bv_e( $text ) . '</h1>';
}

function bv_ep( $html ) {
	return '<p style="margin:0 0 16px;">' . $html . '</p>';
}

function bv_ebutton( $label, $url ) {
	return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr><td style="border-radius:999px;background:#D4196C;">'
		. '<a href="' . bv_e( $url ) . '" style="display:inline-block;padding:14px 30px;font-family:Nunito,Segoe UI,Helvetica,Arial,sans-serif;font-size:16px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:999px;">'
		. bv_e( $label ) . '</a></td></tr></table>';
}

function bv_ebox( $html ) {
	return '<div style="background:#FDF0F4;border-radius:14px;padding:16px 18px;margin:0 0 20px;">' . $html . '</div>';
}

/** The order lines and totals as an email table. */
function bv_eorder( array $o ) {
	$rows = '';
	foreach ( $o['lines'] as $l ) {
		$rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #EFDDE2;">' . (int) $l['qty'] . ' &times; ' . bv_e( $l['name'] ) . '</td>'
			. '<td align="right" style="padding:8px 0;border-bottom:1px solid #EFDDE2;white-space:nowrap;">' . bv_rand( $l['total'] ) . '</td></tr>';
	}
	$rows .= '<tr><td style="padding:8px 0;color:#5F5462;">Delivery</td><td align="right" style="padding:8px 0;color:#5F5462;">' . ( $o['delivery'] ? bv_rand( $o['delivery'] ) : 'Free' ) . '</td></tr>'
		. '<tr><td style="padding:8px 0;font-weight:800;font-size:18px;">Total</td><td align="right" style="padding:8px 0;font-weight:800;font-size:18px;">' . bv_rand( $o['total'] ) . '</td></tr>';
	return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;font-size:15px;">'
		. '<tr><td colspan="2" style="padding:0 0 6px;font-weight:800;">Order ' . bv_e( $o['id'] ) . '</td></tr>' . $rows . '</table>';
}

function bv_text_order( array $o ) {
	$out = array( 'Order ' . $o['id'] );
	foreach ( $o['lines'] as $l ) {
		$out[] = sprintf( '%d x %s  %s', $l['qty'], $l['name'], bv_rand( $l['total'] ) );
	}
	$out[] = 'Delivery: ' . ( $o['delivery'] ? bv_rand( $o['delivery'] ) : 'Free' );
	$out[] = 'Total: ' . bv_rand( $o['total'] );
	return implode( "\n", $out );
}

function bv_text_footer() {
	$shop = bv_shop();
	return "\n\nQuestions? WhatsApp us on +{$shop['whatsapp']} or reply to this email.\n\nLove,\nThe BabyVibes team\n"
		. bv_site_url( '' ) . "\nBabyVibes is trading as Hostdigi (Pty) Ltd.";
}

function bv_track_url( array $o ) {
	return bv_site_url( 'track.html?order=' . rawurlencode( $o['id'] ) );
}

// The emails --------------------------------------------------------------------------------

/** Welcome email after creating an account. */
function bv_email_welcome( array $c ) {
	$name = $c['first_name'];
	$html = bv_email_layout(
		'Welcome to BabyVibes!',
		bv_eh( "Welcome to BabyVibes, $name!" )
		. bv_ep( "Thank you for creating an account. We're a small shop run by moms, and we're so happy you're here." )
		. bv_ep( 'With your account you can:' )
		. '<ul style="margin:0 0 20px;padding-left:20px;"><li>Track your orders and see your order history</li><li>Check out faster with your saved address</li><li>Leave reviews on the things you buy</li></ul>'
		. bv_ebutton( 'Start shopping', bv_site_url( 'shop.html' ) )
		. bv_ep( '<span style="color:#5F5462;font-size:14px;">You can manage your account at any time on <a href="' . bv_e( bv_site_url( 'account.html' ) ) . '" style="color:#B8155D;">My account</a>.</span>' )
	);
	$text = "Welcome to BabyVibes, $name!\n\nThank you for creating an account. You can now track your orders, see your order history and check out faster.\n\nStart shopping: "
		. bv_site_url( 'shop.html' ) . "\nMy account: " . bv_site_url( 'account.html' ) . bv_text_footer();
	return bv_send_email( $c['email'], trim( $c['first_name'] . ' ' . $c['last_name'] ), 'Welcome to BabyVibes, ' . $name . '!', $html, $text, 'welcome' );
}

/** "Thank you for your order" after payment. */
function bv_email_order_confirmation( array $o ) {
	$c    = $o['customer'];
	$html = bv_email_layout(
		"Thank you for your order {$o['id']}. We're packing it soon.",
		bv_eh( "Thank you for your order, {$c['firstName']}!" )
		. bv_ep( "We've received your payment and your order is confirmed. We'll pack it with love and email you the tracking number as soon as it ships." )
		. bv_eorder( $o )
		. bv_ebox( '<strong>Delivering to</strong><br>' . nl2br( bv_e( trim( $c['firstName'] . ' ' . $c['lastName'] ) . "\n" . bv_address( $c ) ) ) )
		. bv_ebutton( 'Track your order', bv_track_url( $o ) )
		. bv_ep( '<span style="color:#5F5462;font-size:14px;">Most orders arrive within 2 to 4 working days in the main centres.</span>' )
	);
	$text = "Thank you for your order, {$c['firstName']}!\n\nWe've received your payment and your order is confirmed. We'll email your tracking number when it ships.\n\n"
		. bv_text_order( $o ) . "\n\nDelivering to\n" . bv_address( $c ) . "\n\nTrack your order: " . bv_track_url( $o ) . bv_text_footer();
	return bv_send_email( $c['email'], trim( $c['firstName'] . ' ' . $c['lastName'] ), "Thank you for your order ({$o['id']})", $html, $text, 'order_confirmation', $o['id'] );
}

/** New paid order alert for the shop. */
function bv_email_new_order_admin( array $o ) {
	$c     = $o['customer'];
	$to    = bv_config()['orders_email'] ? bv_config()['orders_email'] : bv_shop()['email'];
	$admin = bv_site_url( 'admin/order.php?id=' . rawurlencode( $o['id'] ) );
	$html  = bv_email_layout(
		"New order {$o['id']}: " . bv_rand( $o['total'] ),
		bv_eh( 'New order: ' . bv_rand( $o['total'] ) )
		. bv_ep( 'A new order has been paid. Time to pack!' )
		. bv_eorder( $o )
		. bv_ebox(
			'<strong>Customer</strong><br>' . bv_e( "{$c['firstName']} {$c['lastName']}" ) . '<br>' . bv_e( $c['email'] ) . '<br>' . bv_e( $c['phone'] )
			. '<br><br><strong>Deliver to</strong><br>' . nl2br( bv_e( bv_address( $c ) ) )
			. ( $c['notes'] ? '<br><br><strong>Notes</strong><br>' . nl2br( bv_e( $c['notes'] ) ) : '' )
		)
		. bv_ebutton( 'Open the order', $admin )
	);
	$text = "A new order has been paid.\n\n" . bv_text_order( $o ) . "\n\nCustomer\n{$c['firstName']} {$c['lastName']}\n{$c['email']}\n{$c['phone']}\n\nDeliver to\n"
		. bv_address( $c ) . ( $c['notes'] ? "\n\nNotes\n{$c['notes']}" : '' ) . "\n\nOpen the order: $admin";
	return bv_send_email( $to, 'BabyVibes', "New paid order {$o['id']} (" . bv_rand( $o['total'] ) . ')', $html, $text, 'new_order_admin', $o['id'] );
}

/** Order shipped, with the tracking number. */
function bv_email_shipped( array $o ) {
	$c   = $o['customer'];
	$t   = $o['tracking'];
	$box = '<strong>Courier:</strong> ' . bv_e( $t['courier'] ?: 'Courier' ) . '<br><strong>Tracking number:</strong> ' . bv_e( $t['number'] );
	$html = bv_email_layout(
		"Your BabyVibes order {$o['id']} is on its way!",
		bv_eh( 'Your order is on its way!' )
		. bv_ep( "Good news, {$c['firstName']}: your order <strong>" . bv_e( $o['id'] ) . '</strong> has left our hands and is on its way to you.' )
		. bv_ebox( $box )
		. bv_ebutton( $t['url'] ? 'Track with the courier' : 'Track your order', $t['url'] ? $t['url'] : bv_track_url( $o ) )
		. bv_eorder( $o )
	);
	$text = "Your order is on its way!\n\nGood news, {$c['firstName']}: order {$o['id']} has shipped.\n\nCourier: " . ( $t['courier'] ?: 'Courier' )
		. "\nTracking number: {$t['number']}\nTrack it: " . ( $t['url'] ? $t['url'] : bv_track_url( $o ) ) . "\n\n" . bv_text_order( $o ) . bv_text_footer();
	return bv_send_email( $c['email'], trim( $c['firstName'] . ' ' . $c['lastName'] ), "Your order {$o['id']} is on its way!", $html, $text, 'order_shipped', $o['id'] );
}

/** Order delivered: thank you and a review request. */
function bv_email_delivered( array $o ) {
	$c      = $o['customer'];
	$review = bv_site_url( 'review.html?order=' . rawurlencode( $o['id'] ) );
	$html   = bv_email_layout(
		'Your BabyVibes order has arrived. How did we do?',
		bv_eh( 'Your order has arrived!' )
		. bv_ep( "Hi {$c['firstName']}, we hope your little one loves their new things. Thank you for supporting a small, mom-run business." )
		. bv_ep( 'Would you take a minute to tell other moms how we did? It means the world to us.' )
		. bv_ebutton( 'Leave a review', $review )
		. bv_ep( '<span style="color:#5F5462;font-size:14px;">Something not right? Reply to this email within 7 days and we\'ll sort it out.</span>' )
	);
	$text = "Your order has arrived!\n\nHi {$c['firstName']}, we hope your little one loves their new things. Would you tell other moms how we did?\n\nLeave a review: $review\n\nSomething not right? Reply within 7 days and we'll sort it out." . bv_text_footer();
	return bv_send_email( $c['email'], trim( $c['firstName'] . ' ' . $c['lastName'] ), 'Your order has arrived. How did we do?', $html, $text, 'order_delivered', $o['id'] );
}

/** Order cancelled or refunded. */
function bv_email_cancelled( array $o, $refunded, $reason = '' ) {
	$c     = $o['customer'];
	$title = $refunded ? 'Your refund is on its way' : 'Your order has been cancelled';
	$line  = $refunded
		? 'We have refunded ' . bv_rand( $o['total'] ) . ' for order <strong>' . bv_e( $o['id'] ) . '</strong> to the card or account you paid with. Your bank may take 5 to 10 working days to show it.'
		: 'Your order <strong>' . bv_e( $o['id'] ) . '</strong> has been cancelled.';
	$html = bv_email_layout(
		$title,
		bv_eh( $title )
		. bv_ep( "Hi {$c['firstName']}, " . $line )
		. ( $reason ? bv_ebox( '<strong>Note from us:</strong><br>' . nl2br( bv_e( $reason ) ) ) : '' )
		. bv_ep( "We're sorry for any inconvenience. If you have questions, just reply to this email." )
		. bv_ebutton( 'Visit the shop', bv_site_url( 'shop.html' ) )
	);
	$text = "$title\n\nHi {$c['firstName']}, " . strip_tags( $line ) . ( $reason ? "\n\nNote from us: $reason" : '' ) . bv_text_footer();
	return bv_send_email( $c['email'], trim( $c['firstName'] . ' ' . $c['lastName'] ), "$title ({$o['id']})", $html, $text, $refunded ? 'order_refunded' : 'order_cancelled', $o['id'] );
}

/** Password reset link. */
function bv_email_password_reset( array $c, $link ) {
	$html = bv_email_layout(
		'Reset your BabyVibes password',
		bv_eh( 'Reset your password' )
		. bv_ep( "Hi {$c['first_name']}, we received a request to reset the password for your BabyVibes account." )
		. bv_ebutton( 'Choose a new password', $link )
		. bv_ep( '<span style="color:#5F5462;font-size:14px;">This link works for 1 hour. If you didn\'t ask to reset your password, you can ignore this email: your password stays the same.</span>' )
	);
	$text = "Reset your password\n\nHi {$c['first_name']}, use this link to choose a new password (it works for 1 hour):\n$link\n\nIf you didn't ask for this, ignore this email." . bv_text_footer();
	return bv_send_email( $c['email'], trim( $c['first_name'] . ' ' . $c['last_name'] ), 'Reset your BabyVibes password', $html, $text, 'password_reset' );
}

/** New review waiting for approval (to the shop). */
function bv_email_review_admin( array $r ) {
	$to   = bv_config()['orders_email'] ? bv_config()['orders_email'] : bv_shop()['email'];
	$link = bv_site_url( 'admin/reviews.php' );
	$html = bv_email_layout(
		"New {$r['rating']}-star review",
		bv_eh( "New {$r['rating']}-star review" )
		. bv_ebox( '&ldquo;' . nl2br( bv_e( $r['text'] ) ) . '&rdquo;<br><br><strong>' . bv_e( $r['name'] ) . '</strong>' . ( $r['location'] ? ', ' . bv_e( $r['location'] ) : '' ) . ' &middot; order ' . bv_e( $r['order_id'] ) )
		. bv_ep( 'It is not on the website until you approve it.' )
		. bv_ebutton( 'Review it', $link )
	);
	$text = "New {$r['rating']}-star review from {$r['name']} (order {$r['order_id']}):\n\n\"{$r['text']}\"\n\nApprove or reject it: $link";
	return bv_send_email( $to, 'BabyVibes', "New {$r['rating']}-star review from {$r['name']}", $html, $text, 'review_admin', $r['order_id'] );
}
