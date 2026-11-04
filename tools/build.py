#!/usr/bin/env python3
"""Build the BabyVibes pages.

Each page's content lives in tools/pages/<name>.html. This script wraps it in the shared
header and footer below and writes <name>.html to the site root.

Run it after changing a page, the header/footer, or the contact details in data/shop.json:

    python3 tools/build.py

Products do NOT need a rebuild: they're read from data/products.json in the browser.

Placeholders you can use in pages:
    {{icon:NAME}}         an icon (truck, lock, heart, chat, star, gift, mail)
    {{email}} {{whatsapp}} {{whatsapp_display}} {{hours}}
    {{flat_rate}} {{free_from}}   delivery prices from data/shop.json, e.g. R99
    {{year}}
"""
import datetime
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PAGES = Path(__file__).resolve().parent / "pages"
SHOP = json.loads((ROOT / "data" / "shop.json").read_text())

NAV = [
    ("home", "index.html", "Home"),
    ("shop", "shop.html", "Shop"),
    ("about", "about.html", "About"),
    ("faq", "faq.html", "FAQ & Delivery"),
    ("contact", "contact.html", "Contact"),
]

ICONS = {
    "truck": '<path d="M3 6h11v9H3zM14 9h4l3 3v3h-7z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="7" cy="17.5" r="2" fill="currentColor"/><circle cx="17" cy="17.5" r="2" fill="currentColor"/>',
    "lock": '<path fill="currentColor" d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5zm-3 8V7a3 3 0 1 1 6 0v3H9z"/>',
    "heart": '<path fill="currentColor" d="M12 21s-7.5-4.6-9.6-9.3C.9 8.3 3 4.5 6.6 4.5c2.1 0 3.6 1.2 4.4 2.6.8-1.4 2.3-2.6 4.4-2.6 3.6 0 5.7 3.8 4.2 7.2C19.5 16.4 12 21 12 21z"/>',
    "chat": '<path fill="currentColor" d="M12 3C6.5 3 2 6.8 2 11.5c0 2.4 1.2 4.6 3.1 6.1L4 21l4.2-1.9c1.2.4 2.5.6 3.8.6 5.5 0 10-3.8 10-8.5S17.5 3 12 3z"/>',
    "star": '<path fill="currentColor" d="M12 2.5l2.9 6 6.6.8-4.9 4.6 1.3 6.6L12 17.3l-5.9 3.2 1.3-6.6L2.5 9.3l6.6-.8z"/>',
    "gift": '<path d="M4 10h16v11H4zM2 6h20v4H2zM12 6v15M12 6c-1.5-3-5-3.5-5-1.2C7 6 9.5 6 12 6zm0 0c1.5-3 5-3.5 5-1.2C17 6 14.5 6 12 6z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
    "mail": '<path d="M3 5h18v14H3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M3 6l9 7 9-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
}

# Wordmark in the logo colours. If you save the real logo as assets/img/logo.svg (or .png / .webp),
# the header and footer use that file instead.
WORDMARK = ('<span class="wordmark"><span class="wm-baby">Baby</span><span class="wm-vibes">Vibes</span>'
            '<svg class="wm-swoosh" viewBox="0 0 120 16" aria-hidden="true" preserveAspectRatio="none">'
            '<path d="M2 11 C 30 4, 70 3, 100 8" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
            '<path d="M110 13.5c-2.6-1.7-5.2-3.6-5.2-6 0-1.5 1.1-2.6 2.5-2.6.9 0 1.9.5 2.7 1.6.8-1.1 1.8-1.6 2.7-1.6 '
            '1.4 0 2.5 1.1 2.5 2.6 0 2.4-2.6 4.3-5.2 6z" fill="currentColor"/></svg></span>')


def logo_html():
    for ext in ("svg", "png", "webp"):
        if (ROOT / "assets" / "img" / f"logo.{ext}").exists():
            return f'<img class="logo-img" src="assets/img/logo.{ext}" alt="BabyVibes" width="613" height="176">'
    return WORDMARK


CART_ICON = ('<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.5L20.5 8H6.2" '
             'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
             '<circle cx="10" cy="20" r="1.5" fill="currentColor"/><circle cx="17" cy="20" r="1.5" fill="currentColor"/></svg>')
USER_ICON = ('<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="2"/>'
             '<path d="M4 20c1.5-4 4.5-6 8-6s6.5 2 8 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>')
MENU_ICON = ('<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" '
             'stroke-width="2.2" stroke-linecap="round"/></svg>')


def money(rand):
    return "R" + f"{rand:,.0f}".replace(",", " ")


def wa_display(number):
    n = str(number)
    if n.startswith("27") and len(n) == 11:
        return f"0{n[2:4]} {n[4:7]} {n[7:]}"
    return "+" + n


def icon(name):
    return f'<svg viewBox="0 0 24 24" aria-hidden="true">{ICONS[name]}</svg>'


def fill(text):
    delivery = SHOP["delivery"]
    values = {
        "email": SHOP["email"],
        "whatsapp": SHOP["whatsapp"],
        "whatsapp_display": wa_display(SHOP["whatsapp"]),
        "hours": SHOP["hours"],
        "flat_rate": money(delivery["flatRate"]),
        "free_from": money(delivery["freeFrom"]) if delivery.get("freeFrom") else "",
        "year": str(datetime.date.today().year),
    }
    promo = SHOP.get("promo") or {}
    values.update({f"promo_{k}": promo.get(k, "") for k in ("name", "headline", "text", "link")})
    text = re.sub(r"\{\{icon:(\w+)\}\}", lambda m: icon(m.group(1)), text)
    return re.sub(r"\{\{(\w+)\}\}", lambda m: values[m.group(1)], text)


# Banks and cards shown in the footer's secure payment strip. Drop each bank's official logo into
# assets/img/banks/ as <slug>.svg, .png or .webp and it replaces the name badge on the next build.
BANKS = [
    ("absa", "Absa"),
    ("fnb", "FNB"),
    ("capitec", "Capitec"),
    ("tymebank", "TymeBank"),
    ("nedbank", "Nedbank"),
    ("standard-bank", "Standard Bank"),
]
CARDS = [("mastercard", "Mastercard"), ("visa", "Visa")]


def badge(slug, name, folder):
    for ext in ("svg", "png", "webp"):
        if (ROOT / "assets" / "img" / folder / f"{slug}.{ext}").exists():
            return f'<li class="pay-logo"><img src="assets/img/{folder}/{slug}.{ext}" alt="{name}" height="28" loading="lazy"></li>'
    return f'<li class="pay-name">{name}</li>'


def payments():
    banks = "".join(badge(s, n, "banks") for s, n in BANKS)
    cards = "".join(badge(s, n, "cards") for s, n in CARDS)
    return f"""<section class="payments" aria-labelledby="pay-title">
   <h2 id="pay-title">{icon("lock")} Secure payment</h2>
   <p>Pay safely with Yoco using a card from any South African bank. Your card details are encrypted and never reach us.</p>
   <ul class="pay-row" aria-label="Banks and cards accepted">{banks}{cards}</ul>
  </section>"""


def parse(path):
    raw = path.read_text()
    m = re.match(r"---\n(.*?)\n---\n", raw, re.S)
    meta = dict(line.split(": ", 1) for line in m.group(1).splitlines())
    return meta, raw[m.end():]


def layout(meta, body):
    current = meta.get("nav", "")
    here = ' aria-current="page"'
    nav = "".join(
        f'<li><a href="{href}"{here if key == current else ""}>{label}</a></li>'
        for key, href, label in NAV)
    free = SHOP["delivery"].get("freeFrom")
    free_text = f"Free delivery on orders over {money(free)}" if free else ""
    promo = SHOP.get("promo")
    if promo:
        # store.js swaps this for a live countdown, and back to free_text once the promotion ends.
        announce = (f'<div class="announce" data-promo-bar data-fallback="{free_text}">'
                    f'<a href="{promo["link"]}"><strong>{promo["name"]} special</strong></a>'
                    f'<span data-promo-label></span> <span data-countdown="compact"></span></div>')
    else:
        announce = f'<div class="announce">{free_text}</div>' if free_text else ""
    year = datetime.date.today().year
    return f"""<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{meta["title"]}</title>
<meta name="description" content="{meta["description"]}">
<meta property="og:title" content="{meta["title"]}">
<meta property="og:description" content="{meta["description"]}">
<meta property="og:type" content="website">
<meta name="theme-color" content="#FDF0F4">
{'<meta name="robots" content="noindex">' if meta.get("noindex") else ""}
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600&family=Nunito:wght@400;600;700;800&display=swap">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body data-page="{meta["page"]}">
<a class="sr-only" href="#main">Skip to content</a>
{announce}
<header class="site-header">
 <div class="wrap">
  <a class="logo" href="index.html" aria-label="BabyVibes home">{logo_html()}</a>
  <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="Menu">{MENU_ICON}</button>
  <nav class="nav" id="site-nav" aria-label="Main"><ul>{nav}</ul></nav>
  <a class="cart-link account-link" href="account.html" aria-label="My account">{USER_ICON}</a>
  <a class="cart-link" href="cart.html" aria-label="Cart">{CART_ICON}<span class="cart-count" data-count="0">0</span></a>
 </div>
</header>
<main id="main">
{fill(body).strip()}
</main>
<footer class="site-footer">
 <div class="wrap">
  <div class="footer-grid">
   <div>
    <a class="logo" href="index.html" aria-label="BabyVibes home">{logo_html()}</a>
    <p>Baby and kids essentials for busy moms: feeding, toys, baby gear and more, delivered across South Africa.</p>
   </div>
   <div>
    <h2>Shop</h2>
    <ul><li><a href="shop.html">All products</a></li><li><a href="account.html">My account</a></li><li><a href="track.html">Track my order</a></li><li><a href="faq.html">FAQ &amp; Delivery</a></li></ul>
   </div>
   <div>
    <h2>Help</h2>
    <ul>
     <li><a href="https://wa.me/{SHOP["whatsapp"]}" rel="noopener">WhatsApp {wa_display(SHOP["whatsapp"])}</a></li>
     <li><a href="mailto:{SHOP["email"]}">{SHOP["email"]}</a></li>
     <li><a href="contact.html">Contact us</a></li>
    </ul>
   </div>
  </div>
  {payments()}
  <div class="footer-base">
   <span>&copy; {year} BabyVibes is trading as Hostdigi (Pty) Ltd. Prices in South African rand, including VAT where applicable.</span>
   <span><a href="terms.html">Terms &amp; returns</a> &middot; <a href="privacy.html">Privacy</a></span>
  </div>
 </div>
</footer>
<script src="assets/js/store.js" defer></script>
</body>
</html>
"""


def main():
    for path in sorted(PAGES.glob("*.html")):
        meta, body = parse(path)
        out = ROOT / path.name
        html = layout(meta, body)
        html = "\n".join(line for line in html.splitlines() if line.strip()) + "\n"
        out.write_text(html)
        print("wrote", out.name)


if __name__ == "__main__":
    main()
