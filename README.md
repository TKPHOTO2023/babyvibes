# BabyVibes

Online shop for moms and little ones: fun toys and baby goodies delivered across South Africa. It's a plain
HTML/CSS/JavaScript site with a small PHP back end for payments by **Yoco**. It needs no database or
WordPress, and runs on any standard cPanel hosting with PHP 7.4 or newer.

## What's here

| Path | What it is |
|---|---|
| `index.html`, `shop.html`, `product.html`, `cart.html`, `checkout.html`, `thank-you.html`, `about.html`, `faq.html`, `contact.html`, `terms.html`, `privacy.html` | The pages (built by `tools/build.py`) |
| `data/products.json` | **Your products and categories.** Edit this to add products |
| `data/shop.json` | Shop email, WhatsApp number, office hours and delivery prices |
| `assets/css/style.css` | All styling (colours at the top) |
| `assets/js/store.js` | Product listings, cart and checkout in the browser |
| `assets/img/products/` | Product photos |
| `api/checkout.php` | Re-prices the cart on the server, saves the order, opens Yoco's payment page |
| `api/webhook.php` | Yoco calls this when a payment succeeds: marks the order paid and sends emails |
| `api/order-status.php` | Lets the thank-you page show the order |
| `api/setup-webhook.php` | One-time Yoco webhook setup |
| `api/config.sample.php` | Settings template: copy to `api/config.php` (secret, never committed) |
| `api/orders/` | Saved orders (blocked from the web) |
| `tools/build.py`, `tools/pages/` | Page builder and page contents |
| `tests/` | Local test kit with a fake Yoco (don't upload) |

## How a sale works

1. The shopper adds products to the cart. The cart is saved in their browser.
2. At checkout they enter their details. `api/checkout.php` checks the details, **re-prices everything from
   `data/products.json`** (prices from the browser are ignored), saves the order as *pending* and creates a Yoco
   checkout.
3. The shopper pays on Yoco's secure page by card or Instant EFT.
4. Yoco calls `api/webhook.php`. The signature is checked, the amount must match the order, and then the order is
   marked **paid**. You get an email with the order and delivery address, and the shopper gets a receipt.
5. Yoco sends the shopper back to the thank-you page, which shows the confirmed order and empties the cart.

If someone cancels or their payment fails, they return to checkout with their cart intact.

## Going live

### 1. Upload the site

Upload everything **except `tests/` and `tools/`** to the folder of your subdomain (for example
`public_html/babyvibes`) with cPanel File Manager or FTP. If WordPress is installed there now, use a fresh
subdomain or remove WordPress first. The site must run on **https** (cPanel > SSL/TLS Status > Run AutoSSL).

### 2. Your details

Edit `data/shop.json`:

- your email
- your WhatsApp number in international format without the + (for example `27821234567`)
- office hours
- delivery prices: `flatRate` (now R150) and `freeFrom` (now R750) (set `freeFrom` to `0` for no free delivery)

The email and WhatsApp number also appear in the page footers and the contact page, so after changing them,
rebuild the pages (`python3 tools/build.py`) and upload the `.html` files again.

### 3. Yoco keys

1. Copy `api/config.sample.php` to `api/config.php` and open it. Then set these:
   - `site_url`: your shop address, for example `https://babyvibes.yourdomain.co.za`
   - `yoco_secret_key`: your **test** secret key (`sk_test_...`) from the Yoco portal (Sales > Payment gateway,
     or Developers > Keys)
   - `admin_token`: a long random password of your own
   - `from_email`: an address on your own domain, for example `orders@yourdomain.co.za` (create it in cPanel >
     Email Accounts so emails don't land in spam)
2. Open `https://your-shop/api/setup-webhook.php?token=YOUR_ADMIN_TOKEN` once. It registers the shop with Yoco and
   saves the webhook secret. You should see "Done".

### 4. Test order

Buy something with a Yoco test card (Yoco's developer docs list them; at the time of writing, card
`4111 1111 1111 1111`, any future expiry, any CVV). Check that:

- you land on the thank-you page with "Thank you, ..."
- you receive the new-order email, and the shopper's address receives the receipt
- the payment shows in the Yoco portal under test payments

### 5. Switch to live

Put your **live** key (`sk_live_...`) in `api/config.php`, open `api/setup-webhook.php?token=...` again (live mode
needs its own webhook), and make one small real purchase to confirm.

### 6. Before launch

- [ ] Your WhatsApp number and email in `data/shop.json` (the placeholder shows as 000 000 0000)
- [ ] Your business name, registration and address in `terms.html` (required by the ECTA). Have the terms and
      privacy drafts checked, and remove the "Draft" lines.
- [ ] Delivery times and returns wording match what you actually offer
- [ ] The Talking Cactus details match your stock: it's listed as running on 3 x AA batteries. Say on the product
      whether batteries are included, add the age rating from the box, and listen to the songs (some talking
      cactus toys have shipped with unsuitable lyrics)

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

3. Upload the photos and `data/products.json`. The product appears on the homepage, shop, category and product
   pages straight away, with no rebuild. Check that the file is still valid JSON (paste it into jsonlint.com) if
   the shop stops showing products.

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

## Orders

Paid orders arrive by email. Each order is also saved as a file in `api/orders/` (or wherever `orders_dir` points)
with its status: `pending` (not paid yet), `paid`, `amount_mismatch` (check it in Yoco before sending
anything) or `checkout_failed`. Your Yoco portal is the record of payments and refunds.

## Changing pages

Page text lives in `tools/pages/*.html`, and the header and footer in `tools/build.py`. Edit them, run
`python3 tools/build.py`, and upload the changed `.html` files. Small text fixes can also be made directly in the
root `.html` files, but they'll be overwritten on the next build.

## Testing locally

Uses a fake Yoco, so nothing touches real payments:

```bash
PHP_CLI_SERVER_WORKERS=4 php -d auto_prepend_file=$PWD/tests/mock-yoco.php -S 127.0.0.1:8765 -t .
```

Open http://127.0.0.1:8765, buy something, and click "Pay" on the fake Yoco page. Emails are written to
`tests/mail.log` and orders to `tests/orders/`.
