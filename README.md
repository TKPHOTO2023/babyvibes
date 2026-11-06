# BabyVibes

Online shop for moms: baby and kids essentials delivered across South Africa. The storefront is plain
HTML/CSS/JavaScript; the back end is PHP with a MySQL database, so it runs on any standard cPanel hosting
(PHP 7.4 or newer). Payments are by **Yoco**, emails are sent by **Brevo**.

## What it does

- **Shop:** homepage, shop with categories, product pages with options (such as colour), cart and checkout.
- **Payments:** Yoco's secure payment page (cards). Prices are always re-checked on the server.
- **Customer accounts (optional):** register, log in, order history with tracking, saved address, change or
  reset password. Shoppers can still check out as guests, or create an account at checkout.
- **Order tracking:** anyone can track an order with the order number and email (`track.html`).
- **Automatic emails** in the BabyVibes design, all logged in the admin:

  | Email | Sent when | To |
  |---|---|---|
  | Welcome | An account is created | Customer |
  | Thank you for your order | Yoco confirms payment | Customer |
  | New paid order | Yoco confirms payment | You |
  | Your order is on its way (with tracking) | You mark the order shipped | Customer |
  | Your order has arrived + review request | You mark it delivered | Customer |
  | Cancelled / refund on its way | You cancel or refund it | Customer |
  | Reset your password | Customer asks for it | Customer |
  | New review / payment amount mismatch | A review comes in / Yoco reports a wrong amount | You |

- **Admin back office** at `/admin`:
  - **Dashboard:** revenue, orders, average order, items sold, new accounts, returning buyers and abandoned
    checkouts, compared with the previous period; revenue per day; best sellers; sales by category; a to-do list.
  - **Orders:** search and filter; each order has its history and the actions to mark it shipped (with courier
    and tracking number), delivered, cancelled or refunded, plus internal notes. CSV export.
  - **Customers:** accounts with orders, spend and marketing opt-in.
  - **Reviews:** publish or hide verified customer reviews.
  - **Emails:** every email sent, and any that failed.

## What's here

| Path | What it is |
|---|---|
| `*.html` | The pages (built by `tools/build.py` from `tools/pages/`) |
| `data/products.json` | **Your products and categories.** Edit this to add products |
| `data/shop.json` | Shop email, WhatsApp number, office hours, delivery prices, Black Friday promotion, Google Tag Manager ID, Hotjar code |
| `assets/` | Styles, the shop script, logo, product photos, bank logos |
| `api/config.sample.php` | Settings template: copy to `api/config.php` (passwords and keys; never committed) |
| `api/install.php` | One-time database setup and admin logins |
| `api/lib.php`, `api/emails.php` | Shared back-end code and the email templates (blocked from the web) |
| `api/checkout.php`, `webhook.php`, `order-status.php` | Checkout, Yoco payment confirmation, thank-you page |
| `api/account.php`, `track.php` | Customer accounts and order tracking |
| `api/review.php`, `reviews.php` | Leaving reviews, and the published ones for the homepage |
| `api/setup-webhook.php` | One-time Yoco webhook setup |
| `admin/` | The admin back office |
| `tools/` | Page builder and page contents (don't upload) |
| `tests/` | Local test kit with a fake Yoco (don't upload) |

## How a sale works

1. The shopper adds products to the cart (saved in their browser) and goes to checkout. If they're logged in,
   their details are filled in; otherwise they can check out as a guest or tick "Create an account".
2. `api/checkout.php` checks the details, **re-prices everything from `data/products.json`** (prices from the
   browser are ignored), saves the order in the database as *Awaiting payment* and opens Yoco's payment page.
3. The shopper pays. Yoco calls `api/webhook.php`: the signature is checked, the amount must match, and the order
   becomes **Paid**. The customer gets "Thank you for your order", and you get the new-order email.
4. You pack it, then in the admin mark it **Shipped** with the courier and tracking number (the customer is
   emailed), and later **Delivered** (the customer is asked for a review).

## Going live

You'll need: your cPanel login, your Yoco account, and a free [Brevo](https://www.brevo.com) account.

### 1. Database

In cPanel > **MySQL Databases**:

1. Create a database, for example `babyvibes` (cPanel names it `youruser_babyvibes`).
2. Create a database user with a strong password (use the password generator).
3. Add the user to the database with **All privileges**.

### 2. Upload the site

Upload everything **except `tests/` and `tools/`** to the folder of your subdomain (for example
`public_html/babyvibes`) with cPanel File Manager or FTP. If WordPress is installed there now, use a fresh
subdomain or remove WordPress first. The site must run on **https** (cPanel > SSL/TLS Status > Run AutoSSL).

### 3. Brevo (emails)

1. Sign up at brevo.com (the free plan sends 300 emails a day).
2. **Add and authenticate your domain:** Brevo > Senders, domains & dedicated IPs > Domains > Add a domain.
   Brevo shows a few DNS records (a Brevo code, DKIM and DMARC). Add each one in cPanel > **Zone Editor** for your
   domain, then click Authenticate in Brevo. This is what keeps your emails out of spam, so don't skip it.
3. Add a sender such as `orders@yourdomain.co.za` (Senders > Add a sender). Create that mailbox in cPanel > Email
   Accounts too, so replies and bounces arrive somewhere.
4. Create an API key: Brevo > SMTP & API > API keys > Generate a new API key. Copy it (it starts with `xkeysib-`).

### 4. Settings

Copy `api/config.sample.php` to `api/config.php` and fill in:

- `site_url`: your shop address, for example `https://babyvibes.yourdomain.co.za`
- `db`: the database name, user and password from step 1 (keep `host=localhost`)
- `yoco_secret_key`: your **test** secret key (`sk_test_...`) from the Yoco portal
- `brevo_api_key`, `from_email`: from step 3
- `orders_email`: where new-order alerts go (empty = the email in `data/shop.json`)
- `admin_token`: a long random password of your own (at least 16 characters)

Also edit `data/shop.json` with your email, WhatsApp number (international format without the +, for example
`27821234567`), office hours and delivery prices (`flatRate` is R150 and `freeFrom` R750). These also appear in
the page footers, so rebuild the pages afterwards (`python3 tools/build.py`) and upload the `.html` files.

### 5. Create the database tables and your admin login

Open `https://your-shop/api/install.php?token=YOUR_ADMIN_TOKEN`. It creates the tables, then asks for your name,
email and an admin password (at least 12 characters). Sign in at `https://your-shop/admin/`.

### 6. Connect Yoco

Open `https://your-shop/api/setup-webhook.php?token=YOUR_ADMIN_TOKEN` once. It registers the shop with Yoco so
payments are confirmed automatically. You should see "Done".

### 7. Test everything

With the test key, place an order with a Yoco test card (Yoco's developer docs list them; at the time of writing
`4111 1111 1111 1111`, any future expiry, any CVV), and create an account while you do. Check that:

- the thank-you page shows "Thank you, ..." and the order appears in the admin as **Paid**
- the welcome, order and new-order emails arrive (and not in spam)
- marking it shipped and delivered in the admin sends those emails, and `track.html` shows the progress
- the payment shows in the Yoco portal under test payments

Then delete the test order's data if you like (it's only in test mode) or just leave it.

### 8. Switch to live

Put your **live** key (`sk_live_...`) in `api/config.php`, open `api/setup-webhook.php?token=...` again (live mode
needs its own webhook), and make one small real purchase to confirm.

### 9. Before launch

- [ ] Your WhatsApp number and email in `data/shop.json` (the placeholder shows as 000 000 0000)
- [ ] Hostdigi (Pty) Ltd's registration number, VAT number (if registered) and address in `terms.html`
      (required by the ECTA). Have the terms and privacy drafts checked, and remove the "Draft" lines.
- [ ] Delivery times and returns wording match what you actually offer
- [ ] Product details match your stock (batteries, age ratings, materials)
- [ ] Backups are on: cPanel > Backup (or JetBackup) for both the files and the database. Download a database
      backup now and then (cPanel > phpMyAdmin > Export), since it holds your orders and customers.

### Keeping it safe

- `api/config.php` holds your passwords: never share it or put it on GitHub (it's in `.gitignore`).
- The admin signs out after 8 hours without activity, and logins are rate-limited.
- After setup you can rename or delete `api/install.php`; upload it again if you ever need to add an admin.
- Keep PHP up to date in cPanel > Select PHP Version (8.1 or newer is best).

## Adding a product

1. Put the photos in `assets/img/products/`. Square JPGs about 1000 × 1000 px under 300 KB work best. Name them
   like `soft-bunny-1.jpg`, using lowercase and dashes with no spaces (GitHub uploads called `s-zoom (1).jpg` need
   renaming).
2. Add an entry to `products` in `data/products.json`. Copy the Talking Cactus entry and change:

   | Field | What it does |
   |---|---|
   | `slug` | Web address name, lowercase with dashes (`soft-bunny` → `product.html?p=soft-bunny`) |
   | `name`, `price` | Name and price in rand (a number, no R) |
   | `compareAtPrice` | Old price to show crossed out for a sale, or `null` |
   | `category` | A `slug` from `categories`. Add new categories there (for example `{ "slug": "feeding", "name": "Feeding" }`) |
   | `inStock` | `false` shows "Sold out" and blocks buying |
   | `badge` | Small label such as `"New"` or `"Sale"`, or `null` |
   | `featured` | `true` shows it under "Loved by our moms" on the homepage |
   | `added` | Date added (`YYYY-MM-DD`); newest show first in New arrivals |
   | `images` | Photo paths. The first is the main photo |
   | `short`, `description` | One-line summary, and the longer description (HTML) |
   | `features`, `inTheBox`, `age` | Lists and safety note shown on the product page (optional) |
   | `options` | Optional choice such as colour or size, which the shopper must pick before adding to the cart. Each value can have its own photo (shown when picked) and a `swatch` colour. See the 9-piece feeding set for an example. The choice appears in the cart, at checkout and in the order email |

3. Upload the photos and `data/products.json`. The product appears on the homepage, shop, category and product
   pages straight away, with no rebuild. Check that the file is still valid JSON (paste it into jsonlint.com) if
   the shop stops showing products.

## Google Analytics (through Google Tag Manager)

Every shop page loads your Google Tag Manager container `GTM-T7QQLXGG` (set as `gtmId` in `data/shop.json`;
the admin is never tracked). A cookie banner asks shoppers first: until they click **Accept**, Google's Consent
Mode keeps analytics and ad cookies off, and Google only gets anonymous, cookieless signals. Shoppers can change
their choice with "Cookie settings" in the footer.

The shop sends these GA4 ecommerce events to Tag Manager, with product, colour, quantity and rand values:

| Event | When |
|---|---|
| `view_item_list` | Shop, category and homepage product lists are shown |
| `view_item` | A product page is opened |
| `add_to_cart` / `remove_from_cart` | Products are added (shop grid or product page) / removed in the cart |
| `view_cart`, `begin_checkout`, `add_payment_info` | Cart opened, checkout opened, shopper goes to Yoco to pay |
| `purchase` | The thank-you page confirms payment (with the order number; counted once per order) |
| `sign_up`, `login` | Account created (on the account page or at checkout), customer logs in |

**One-time setup in Google (about 10 minutes)**, because Tag Manager only sends what you tell it to:

1. **Google Analytics:** analytics.google.com > Admin > Create > Property (time zone South Africa, currency
   South African rand) > Web data stream for your shop address. Copy the **Measurement ID** (`G-...`).
2. **Tag Manager** (tagmanager.google.com, container GTM-T7QQLXGG):
   1. Tags > New > **Google Tag**. Tag ID: your `G-...` ID. Trigger: **Initialization - All Pages**. Save.
   2. Tags > New > **Google Analytics: GA4 Event**. Measurement ID: your `G-...` ID. Event name: `{{Event}}`.
      Under More settings > **Ecommerce**, tick **Send Ecommerce data** (data source: Data Layer).
      Trigger: New > **Custom Event**, event name
      `view_item_list|view_item|add_to_cart|remove_from_cart|view_cart|begin_checkout|add_payment_info|purchase|sign_up|login`
      with **Use regex matching** ticked. Save.
   3. Click **Preview**, open your shop, accept cookies and click around: you should see the events fire. Then
      click **Submit > Publish**.
3. In GA4, sales show under Reports > Monetization > Ecommerce purchases (data can take up to a day to appear;
   Reports > Realtime shows visitors straight away). `purchase` is counted as a key event automatically.

The admin dashboard stays the record of real orders and revenue; GA4 adds where visitors come from, what they
look at and where they drop off.

## Hotjar (session recordings and heatmaps)

Hotjar by Contentsquare records visits and builds heatmaps, so you can watch where shoppers click, scroll and get
stuck. The tracking code is `hotjarSrc` in `data/shop.json` (`https://t.contentsquare.net/uxa/39689827b4ce4.js`).

- It loads **only after a shopper clicks Accept** on the cookie banner, and not at all if they decline
  (session recordings need consent under POPIA). The admin is never recorded.
- Everything people type is hidden from recordings, along with the parts of pages that show personal details:
  checkout, account, password reset, order tracking, reviews and the thank-you page (`data-cs-mask` and
  `data-hj-suppress` on those fields and areas).
- Recordings and heatmaps appear in your Hotjar / Contentsquare account a few minutes after accepted visits.
  If nothing shows after a day, check the site address is added to the project in Hotjar's settings.

## Black Friday and other promotions

The `promo` block in `data/shop.json` drives the Black Friday countdown in the top bar of every page and in the
dark banner on the homepage:

- before `start`, it counts down to the start ("starts in")
- between `start` and `end`, it counts down to the end ("ends in") with the `liveHeadline`
- after `end`, it disappears by itself and the top bar goes back to the free-delivery message

Dates are South African time (`+02:00`). Reuse it for any sale by changing the name, dates and text, then run
`python3 tools/build.py`. Delete the whole `promo` block to remove it.

To put products on special, set `price` to the sale price and `compareAtPrice` to the normal price in
`data/products.json`. The old price shows crossed out, and checkout charges the sale price. Remember to change
them back when the sale ends.

## Bank logos in the footer

The footer's "Secure payment" strip shows Visa, Mastercard and the main South African banks as name badges. To use
the real logos, download them from each bank's official brand or media page, save them in `assets/img/banks/`
(see the README there for file names), and run `python3 tools/build.py`.

## Reviews

Only real customers can review. On `review.html` they enter their order number and the email they ordered with,
and rate the shop overall, for professionalism and for convenience. Each order can be reviewed once. The
"Your order has arrived" email asks for a review.

New reviews **don't appear on the site automatically**: you get an email, and publish or hide them in
Admin > Reviews. The homepage shows the latest six, the average ratings and a "Verified buyer" label. While there
are no reviews, it shows a short "we're new" message instead. Publish genuine reviews as they are: fake, hidden or
altered reviews that mislead shoppers break the Consumer Protection Act.

## Orders and statuses

| Status | Meaning |
|---|---|
| Awaiting payment | The shopper went to Yoco but hasn't paid (yet). Counted as an abandoned checkout after an hour |
| Paid, being packed | Paid: pack it and mark it shipped |
| Shipped | Tracking details sent to the customer |
| Delivered | Done; the customer was asked for a review |
| Cancelled / Refunded | Recorded in the admin. Make actual refunds in the Yoco portal |
| Check payment amount | Yoco reported a different amount: check it in Yoco before sending anything |
| Payment page failed | Yoco's page couldn't open (the shopper saw an error and can try again) |

Your Yoco portal remains the record of payments and refunds. Email delivery details (delivered, opened, bounced)
are in Brevo > Transactional > Logs.

## Changing pages

Page text lives in `tools/pages/*.html`, and the header and footer in `tools/build.py`. Edit them, run
`python3 tools/build.py`, and upload the changed `.html` files. Small text fixes can also be made directly in the
root `.html` files, but they'll be overwritten on the next build.

## Testing locally

Uses SQLite instead of MySQL and a fake Yoco, so nothing touches real payments or sends real emails:

```bash
php -d auto_prepend_file=$PWD/tests/mock-yoco.php api/install.php --email=you@example.com --password='a-long-test-password'
```

```bash
PHP_CLI_SERVER_WORKERS=4 php -d auto_prepend_file=$PWD/tests/mock-yoco.php -S 127.0.0.1:8765 -t .
```

Open http://127.0.0.1:8765, buy something, and click "Pay" on the fake Yoco page. The admin is at
http://127.0.0.1:8765/admin/. Emails are written to `tests/mail.log` (and their HTML to `tests/mail-html/`); the
database is `tests/test.db`.
