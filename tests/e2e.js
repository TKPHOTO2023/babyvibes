/*
 * BabyVibes end-to-end tests: drives the real shop in a browser against the PHP back end,
 * with a fake Yoco and emails written to tests/mail.log. Run with tests/run.sh (see tests/README.md).
 */
'use strict';
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const http = require('http');

const B = 'http://127.0.0.1:8765/';
const ROOT = path.resolve(__dirname, '..');
const MAIL = path.join(__dirname, 'mail.log');
const SHOTS = process.env.BV_SHOTS || '';
let passed = 0;
const failures = [];

function check(name, ok, detail) {
	if (ok) { passed++; console.log('  ok   ' + name); }
	else { failures.push(name); console.log('  FAIL ' + name + (detail !== undefined ? '  -> ' + JSON.stringify(detail) : '')); }
}
function mails() {
	if (!fs.existsSync(MAIL)) { return []; }
	return fs.readFileSync(MAIL, 'utf8').split('-----\n').filter(Boolean).map(function (m) {
		const g = function (k) { const r = new RegExp('^' + k + ': (.*)$', 'm').exec(m); return r ? r[1] : ''; };
		return { to: g('To'), subject: g('Subject'), template: g('Template'), body: m };
	});
}
function getJson(url) {
	return new Promise(function (res, rej) {
		http.get(url, function (r) { let d = ''; r.on('data', function (c) { d += c; }); r.on('end', function () { try { res(JSON.parse(d)); } catch (e) { rej(new Error(d)); } }); }).on('error', rej);
	});
}
function db(sql) {
	// Reads the test database through PHP, so it works for both SQLite and MySQL.
	const { execFileSync } = require('child_process');
	const code = 'require "tests/mock-yoco.php"; require "api/lib.php"; echo json_encode(bv_rows($argv[1]));';
	return JSON.parse(execFileSync('php', ['-r', code, sql], { cwd: ROOT, env: process.env }).toString());
}
async function shot(page, name) { if (SHOTS) { await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: true }); } }
async function fillCheckout(p, c) {
	for (const k of Object.keys(c)) {
		if (k === 'province') { await p.selectOption('#province', c[k]); } else { await p.fill('#' + k, c[k]); }
	}
}
async function addSet(p, colour, n) {
	await p.goto(B + 'product.html?p=silicone-baby-feeding-set-9-piece'); await p.waitForSelector('#add');
	await p.click('[data-opt="' + colour + '"]');
	for (let i = 1; i < (n || 1); i++) { await p.click('[data-step="1"]'); }
	await p.click('#add');
}
const ADDRESS = { phone: '0821234567', address1: '12 Jacaranda St', suburb: 'Hatfield', city: 'Pretoria', province: 'Gauteng', postcode: '0083' };

(async function main() {
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
	await ctx.route(/googletagmanager\.com|contentsquare\.net|fonts\.(googleapis|gstatic)\.com/, function (r) { r.fulfill({ status: 200, body: '' }); });
	const p = await ctx.newPage();
	const errors = [];
	p.on('pageerror', function (e) { errors.push(e.message); });

	console.log('\nShop pages');
	for (const pg of ['index', 'shop', 'product', 'cart', 'checkout', 'account', 'track', 'faq', 'about', 'contact', 'terms', 'privacy', 'review', 'thank-you', 'reset-password']) {
		const r = await p.goto(B + pg + '.html' + (pg === 'product' ? '?p=talking-cactus' : ''));
		check(pg + '.html loads', r.status() === 200, r.status());
	}
	await p.goto(B + 'index.html'); await p.waitForSelector('#new-arrivals .card');
	check('homepage lists products', await p.locator('#new-arrivals .card').count() >= 4);
	await p.click('#consent [data-consent="granted"]');

	console.log('\nAccounts');
	await p.goto(B + 'account.html'); await p.waitForSelector('#register-form');
	await p.fill('#rg-first', 'Lerato'); await p.fill('#rg-last', 'Dlamini'); await p.fill('#rg-email', 'lerato@example.com'); await p.fill('#rg-pass', 'lerato-pass-1');
	await p.click('#register-form button'); await p.waitForSelector('#hello');
	check('register logs the customer in', (await p.textContent('#hello')).includes('Lerato'));
	check('welcome email sent', mails().some(function (m) { return m.template === 'welcome' && m.to === 'lerato@example.com'; }));
	await p.click('#logout'); await p.waitForSelector('#login-form');
	await p.fill('#li-email', 'lerato@example.com'); await p.fill('#li-pass', 'nope-nope'); await p.click('#login-form button');
	await p.waitForSelector('#login-alert:not([hidden])');
	check('wrong password refused', (await p.textContent('#login-alert')).includes("don't match"));
	await p.fill('#li-pass', 'lerato-pass-1'); await p.click('#login-form button'); await p.waitForSelector('#hello');
	check('login works', true);

	console.log('\nCheckout and Yoco payment (signed-in customer)');
	await addSet(p, 'Blue', 2);
	await p.goto(B + 'product.html?p=talking-cactus'); await p.waitForSelector('#add'); await p.click('#add');
	await p.goto(B + 'checkout.html'); await p.waitForSelector('.signed-in');
	await fillCheckout(p, ADDRESS);
	await p.click('#pay'); await p.waitForSelector('#fake-pay');
	const yocoReq = fs.readFileSync(path.join(__dirname, 'yoco.log'), 'utf8').trim().split('\n').map(JSON.parse).filter(function (x) { return x[1] === '/checkouts'; }).pop();
	check('Yoco checkout amount is server-priced (2 x R689 + R380, free delivery)', yocoReq[2].amount === 175800, yocoReq[2].amount);
	check('Yoco checkout has ZAR, success, cancel and failure URLs', yocoReq[2].currency === 'ZAR' && /thank-you\.html\?order=BV/.test(yocoReq[2].successUrl) && /cancelled=1/.test(yocoReq[2].cancelUrl) && /failed=1/.test(yocoReq[2].failureUrl), yocoReq[2]);
	await p.click('#fake-pay'); await p.waitForSelector('#thanks .summary', { timeout: 20000 });
	const order1 = new URL(p.url()).searchParams.get('order');
	check('thank-you page confirms the paid order', (await p.textContent('#thanks h1')).includes('Thank you, Lerato'));
	check('cart emptied after payment', (await p.textContent('.cart-count')) === '0');
	const o1 = db("SELECT status, total, payment_id, customer_id FROM orders WHERE id = '" + order1 + "'")[0];
	check('order saved as paid with payment id and account', o1.status === 'paid' && String(o1.total) === '175800' && o1.payment_id && o1.customer_id, o1);
	check('order confirmation email to customer', mails().some(function (m) { return m.template === 'order_confirmation' && m.to === 'lerato@example.com' && m.subject.includes(order1); }));
	check('new order email to the shop', mails().some(function (m) { return m.template === 'new_order_admin' && m.to === 'owner@example.test' && m.body.includes('Colour: Blue'); }));
	await shot(p, 'thank-you');

	console.log('\nDeclined and cancelled payments');
	await p.goto(B + 'product.html?p=talking-cactus'); await p.waitForSelector('#add'); await p.click('#add');
	await p.goto(B + 'checkout.html'); await p.waitForSelector('.signed-in'); await p.click('#pay'); await p.waitForSelector('#fake-decline');
	await p.click('#fake-decline'); await p.waitForSelector('#checkout-alert:not([hidden])');
	check('declined card returns to checkout with a message', (await p.textContent('#checkout-alert')).includes("didn't go through"));
	check('cart kept after a decline', (await p.textContent('.cart-count')) === '1');
	const declined = db("SELECT o.status FROM orders o JOIN order_events e ON e.order_id = o.id WHERE e.type = 'payment_failed'");
	check('declined order stays unpaid and is logged', declined.length === 1 && declined[0].status === 'pending', declined);
	await p.click('#pay'); await p.waitForSelector('#fake-cancel'); await p.click('#fake-cancel'); await p.waitForSelector('#checkout-alert:not([hidden])');
	check('cancelled payment returns to checkout', (await p.textContent('#checkout-alert')).includes('Payment cancelled'));

	console.log('\nAdmin: dashboard, shipping, delivery');
	const a = await ctx.newPage();
	a.on('pageerror', function (e) { errors.push('admin: ' + e.message); });
	await a.goto(B + 'admin/index.php');
	check('admin needs a login', a.url().includes('login.php'));
	await a.fill('[name=email]', 'owner@example.test'); await a.fill('[name=password]', 'wrong-password'); await a.click('button.btn');
	check('wrong admin password refused', (await a.content()).includes("don't match"));
	await a.fill('[name=email]', 'owner@example.test'); await a.fill('[name=password]', 'test-admin-password-123'); await a.click('button.btn');
	await a.waitForSelector('.kpis');
	const kpi = await a.locator('.kpi .num').allTextContents();
	check('dashboard revenue shows the paid order', kpi[0].replace(/\s/g, '') === 'R1758.00', kpi);
	check('dashboard counts 1 order', kpi[1] === '1', kpi);
	check('dashboard to-do shows an order to ship', (await a.textContent('.todo')).includes('1 paid order'));
	check('dashboard best sellers list the feeding set', (await a.textContent('.cols')).includes('9-Piece Silicone Baby Feeding Set'));
	await shot(a, 'admin-dashboard');
	await a.goto(B + 'admin/order.php?id=' + order1);
	await a.fill('[name=tracking_number]', 'TCG123456789'); await a.fill('[name=tracking_url]', 'https://thecourierguy.co.za/tracking/TCG123456789');
	await a.click('form:has([value=ship]) button.btn'); await a.waitForSelector('.flash');
	check('mark shipped', (await a.textContent('.flash')).includes('emailed the tracking'));
	check('shipped email with tracking number', mails().some(function (m) { return m.template === 'order_shipped' && m.body.includes('TCG123456789'); }));
	const g = await (await browser.newContext()).newPage();
	await g.goto(B + 'track.html?order=' + order1); await g.fill('#t-email', 'lerato@example.com'); await g.click('#track-form button'); await g.waitForSelector('.steps');
	check('guest tracking shows Shipped and the tracking number', (await g.textContent('.order-head .badge-st')) === 'Shipped' && (await g.textContent('.tracking')).includes('TCG123456789'));
	await g.fill('#t-email', 'someone@else.com'); await g.click('#track-form button'); await g.waitForSelector('#track-alert:not([hidden])');
	check('tracking refuses the wrong email', true);
	await a.click('form:has([value=deliver]) button.btn'); await a.waitForSelector('.flash');
	check('delivered email asks for a review', mails().some(function (m) { return m.template === 'order_delivered' && m.body.includes('review.html?order=' + order1); }));

	console.log('\nReviews');
	await p.goto(B + 'review.html?order=' + order1); await p.waitForSelector('.stars button');
	for (const n of ['rating', 'professionalism', 'convenience']) { await p.click('[data-name=' + n + '] [data-v="5"]'); }
	await p.fill('#text', 'Fast delivery to Pretoria and the feeding set is gorgeous.'); await p.click('#send-review'); await p.waitForSelector('#review-wrap h2');
	await a.goto(B + 'admin/reviews.php'); await a.click('button[value=approve]'); await a.waitForSelector('.flash');
	await g.goto(B + 'index.html'); await g.waitForSelector('.review');
	check('approved review shows on the homepage', (await g.textContent('#reviews-summary')).includes('5.0 out of 5'));

	console.log('\nGuest checkout with account creation, and a Yoco refund');
	await g.goto(B + 'product.html?p=baby-support-chair-pink-cat'); await g.waitForSelector('#add'); await g.click('#add');
	await g.goto(B + 'checkout.html'); await g.waitForSelector('#createAccount');
	await fillCheckout(g, Object.assign({ firstName: 'Priya', lastName: 'Pillay', email: 'priya@example.com' }, ADDRESS));
	await g.check('#createAccount'); await g.fill('#password', 'priya-pass-1');
	await g.click('#pay'); await g.waitForSelector('#fake-pay'); await g.click('#fake-pay'); await g.waitForSelector('#thanks .summary');
	const order2 = new URL(g.url()).searchParams.get('order');
	check('guest order paid (R650 + R150 delivery)', String(db("SELECT total FROM orders WHERE id = '" + order2 + "'")[0].total) === '80000');
	check('account created at checkout gets a welcome email', mails().some(function (m) { return m.template === 'welcome' && m.to === 'priya@example.com'; }));
	await a.goto(B + 'admin/order.php?id=' + order2);
	await a.click('summary:has-text("Cancel or refund")'); await a.fill('details.danger textarea', 'Out of stock, sorry!');
	a.once('dialog', function (d) { d.accept(); });
	await a.click('button[value=yoco_refund]'); await a.waitForSelector('.flash');
	const refundReq = fs.readFileSync(path.join(__dirname, 'yoco.log'), 'utf8').trim().split('\n').map(JSON.parse).filter(function (x) { return /\/refund$/.test(x[1]); }).pop();
	check('admin refund calls Yoco (full refund, no body)', refundReq && refundReq[0] === 'POST' && refundReq[2] === null, refundReq);
	check('order waits for Yoco to confirm the refund', db("SELECT status FROM orders WHERE id = '" + order2 + "'")[0].status === 'refund_pending');
	const ch2 = db("SELECT checkout_id FROM orders WHERE id = '" + order2 + "'")[0].checkout_id;
	await getJson(B + 'tests/webhook-sender.php?type=refund.succeeded&checkout=' + ch2 + '&amount=80000');
	await getJson(B + 'tests/webhook-sender.php?type=refund.succeeded&checkout=' + ch2 + '&amount=80000');
	check('refund.succeeded marks the order refunded', db("SELECT status FROM orders WHERE id = '" + order2 + "'")[0].status === 'refunded');
	const refundMails = mails().filter(function (m) { return m.template === 'order_refunded' && m.to === 'priya@example.com'; });
	check('customer gets exactly one refund email, with the reason', refundMails.length === 1 && refundMails[0].body.includes('Out of stock'), refundMails.length);

	console.log('\nRefund failure path');
	await p.goto(B + 'product.html?p=talking-cactus'); await p.waitForSelector('#add'); await p.click('#add');
	await p.goto(B + 'checkout.html'); await p.waitForSelector('.signed-in'); await p.click('#pay'); await p.waitForSelector('#fake-pay'); await p.click('#fake-pay'); await p.waitForSelector('#thanks .summary');
	const order3 = new URL(p.url()).searchParams.get('order');
	fs.writeFileSync(path.join(__dirname, 'refund-fail'), '1');
	await a.goto(B + 'admin/order.php?id=' + order3); await a.click('summary:has-text("Cancel or refund")');
	a.once('dialog', function (d) { d.accept(); });
	await a.click('button[value=yoco_refund]'); await a.waitForSelector('.flash');
	fs.unlinkSync(path.join(__dirname, 'refund-fail'));
	check('a refused refund shows Yoco\'s message and keeps the order paid', (await a.textContent('.flash')).includes('cannot be refunded yet') && db("SELECT status FROM orders WHERE id = '" + order3 + "'")[0].status === 'paid');
	await a.goto(B + 'admin/order.php?id=' + order3); await a.click('summary:has-text("Cancel or refund")');
	a.once('dialog', function (d) { d.accept(); });
	await a.click('button[value=yoco_refund]'); await a.waitForSelector('.flash');
	const ch3 = db("SELECT checkout_id FROM orders WHERE id = '" + order3 + "'")[0].checkout_id;
	await getJson(B + 'tests/webhook-sender.php?type=refund.failed&checkout=' + ch3 + '&amount=53000&reason=insufficient_funds');
	check('refund.failed puts the order back to paid and alerts the shop', db("SELECT status FROM orders WHERE id = '" + order3 + "'")[0].status === 'paid' && mails().some(function (m) { return m.template === 'refund_failed'; }));

	console.log('\nPassword reset');
	const r = await (await browser.newContext()).newPage();
	await r.goto(B + 'account.html'); await r.waitForSelector('[data-forgot]'); await r.click('[data-forgot]');
	await r.fill('#fg-email', 'lerato@example.com'); await r.click('#forgot-form button'); await r.waitForSelector('#forgot-alert:not([hidden])');
	const link = (fs.readFileSync(MAIL, 'utf8').match(/reset-password\.html\?token=[0-9a-f]+/g) || []).pop();
	check('reset email has a link', !!link);
	await r.goto(B + link); await r.fill('#rp1', 'new-lerato-pass'); await r.fill('#rp2', 'new-lerato-pass'); await r.click('#reset-form button'); await r.waitForSelector('#reset-wrap h2');
	check('new password saved', (await r.textContent('#reset-wrap h2')) === 'Password changed');
	await r.goto(B + link); await r.fill('#rp1', 'again-pass-123'); await r.fill('#rp2', 'again-pass-123'); await r.click('#reset-form button'); await r.waitForSelector('#reset-alert:not([hidden])');
	check('reset link only works once', true);

	console.log('\nYoco connection setup');
	const setup1 = await (await fetch(B + 'api/setup-webhook.php?token=test-token-0123456789')).text();
	const setup2 = await (await fetch(B + 'api/setup-webhook.php?token=test-token-0123456789')).text();
	const hooks = JSON.parse(fs.readFileSync(path.join(__dirname, 'yoco-webhooks.json'), 'utf8'));
	check('Connect Yoco registers the webhook', setup1.includes('Done.'), setup1);
	check('running it again replaces the old webhook (only one left)', setup2.includes('Removed the old webhook') && Object.keys(hooks).length === 1, setup2);
	check('setup page refuses a wrong token', (await fetch(B + 'api/setup-webhook.php?token=wrong')).status === 403);

	console.log('\nSystem check');
	await a.goto(B + 'admin/health.php'); await a.waitForSelector('.health-sum');
	const fails = await a.locator('.chk-fail strong').allTextContents();
	check('System check passes everything except https (expected on the local test server)', fails.length === 1 && fails[0].includes('Shop address: http:'), fails);
	await a.click('.inline button.btn'); await a.waitForSelector('.flash');
	check('System check sends a test email', mails().some(function (m) { return m.template === 'test'; }));
	await shot(a, 'admin-health');

	console.log('\nSecurity');
	const post = function (url, body, headers) { return fetch(B + url, { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers || {}), body: JSON.stringify(body) }); };
	check('API refuses posts from other sites', (await post('api/account.php?action=register', {})).status === 400);
	check('order history needs a login', (await fetch(B + 'api/account.php?action=orders')).status === 401);
	check('webhook refuses an unsigned call', (await post('api/webhook.php', { type: 'payment.succeeded' })).status === 403);
	check('admin pages need a login', (await fetch(B + 'admin/orders.php', { redirect: 'manual' })).status === 302);
	check('order status needs the secret token', (await fetch(B + 'api/order-status.php?order=' + order1 + '&t=nope')).status === 404);
	let last = 0;
	for (let i = 0; i < 7; i++) { last = (await post('api/account.php?action=login', { email: 'priya@example.com', password: 'x' }, { 'X-Requested-With': 'BabyVibes' })).status; }
	check('repeated wrong logins are blocked', last === 429, last);
	const paidBefore = mails().filter(function (m) { return m.template === 'order_confirmation'; }).length;
	await getJson(B + 'tests/webhook-sender.php?type=payment.succeeded&checkout=' + db("SELECT checkout_id FROM orders WHERE id = '" + order1 + "'")[0].checkout_id + '&amount=175800');
	check('a repeated payment notification changes nothing', db("SELECT status FROM orders WHERE id = '" + order1 + "'")[0].status === 'delivered' && mails().filter(function (m) { return m.template === 'order_confirmation'; }).length === paidBefore);

	console.log('\nEmails');
	const all = mails();
	const templates = Array.from(new Set(all.map(function (m) { return m.template; }))).sort();
	console.log('  sent: ' + templates.join(', '));
	for (const t of ['welcome', 'order_confirmation', 'new_order_admin', 'order_shipped', 'order_delivered', 'order_refunded', 'refund_failed', 'password_reset', 'review_admin', 'test']) {
		check('email "' + t + '" was sent', templates.includes(t));
	}
	check('every email is logged in the database', Number(db('SELECT COUNT(*) AS n FROM emails')[0].n) === all.length, { db: db('SELECT COUNT(*) AS n FROM emails')[0].n, log: all.length });
	check('no JavaScript errors on any page', errors.length === 0, errors);

	await browser.close();
	console.log('\n' + passed + ' passed, ' + failures.length + ' failed');
	process.exit(failures.length ? 1 : 0);
})().catch(function (e) { console.error('\nTEST RUN CRASHED: ' + e.message); process.exit(2); });
