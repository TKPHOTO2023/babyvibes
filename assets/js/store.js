/* BabyVibes store: product catalogue, cart and checkout. No libraries needed. */
(function () {
	'use strict';

	var CART_KEY = 'bv-cart';
	var CUSTOMER_KEY = 'bv-customer';
	var page = document.body.getAttribute('data-page');
	var params = new URLSearchParams(location.search);

	// Helpers -------------------------------------------------------------------------------

	function $(sel, root) { return (root || document).querySelector(sel); }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function money(rand) {
		var parts = Number(rand).toFixed(2).split('.');
		return 'R' + parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + '.' + parts[1];
	}
	function cents(c) { return money(c / 100); }
	function store(key, value) {
		try {
			if (value === undefined) { return JSON.parse(localStorage.getItem(key) || 'null'); }
			localStorage.setItem(key, JSON.stringify(value));
		} catch (e) { return null; }
	}
	/** POSTs JSON to the shop's API. The custom header proves the request comes from our own pages. */
	function post(url, body) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'BabyVibes' },
			body: JSON.stringify(body || {})
		}).then(function (r) {
			return r.json().catch(function () { return { error: 'Something went wrong. Please try again.' }; })
				.then(function (data) { data._status = r.status; return data; });
		}).catch(function () {
			return { error: 'We couldn\'t reach the shop. Check your internet connection and try again.', _status: 0 };
		});
	}
	function get(url) {
		return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json().then(function (data) { data._status = r.status; return data; }); })
			.catch(function () { return { _status: 0 }; });
	}

	var mePromise = null;
	/** The signed-in customer, or null. */
	function me(refresh) {
		if (!mePromise || refresh) {
			mePromise = get('api/account.php?action=me').then(function (r) { return r.customer || null; });
		}
		return mePromise;
	}

	function clearErrors(form) {
		form.querySelectorAll('.field').forEach(function (f) {
			f.classList.remove('has-error');
			var m = f.querySelector('.err');
			if (m) { m.remove(); }
		});
	}
	function showErrors(form, fields) {
		Object.keys(fields || {}).forEach(function (k) {
			var input = form.elements[k] || form.querySelector('[data-name="' + k + '"]');
			var f = input && (input.closest ? input.closest('.field') : null);
			if (f) {
				f.classList.add('has-error');
				f.insertAdjacentHTML('beforeend', '<p class="err">' + esc(fields[k]) + '</p>');
			}
		});
	}
	function say(el, msg, kind) {
		el.className = 'alert alert--' + (kind || 'error');
		el.textContent = msg;
		el.hidden = false;
		el.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}
	function values(form, names) {
		var out = {};
		names.forEach(function (k) {
			var el = form.elements[k];
			if (!el) { return; }
			out[k] = el.type === 'checkbox' ? el.checked : el.value.trim();
		});
		return out;
	}
	function date(s) {
		var d = new Date(String(s).replace(' ', 'T') + 'Z');
		return isNaN(d) ? '' : d.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' });
	}
	function dateTime(s) {
		var d = new Date(String(s).replace(' ', 'T') + 'Z');
		return isNaN(d) ? '' : d.toLocaleString('en-ZA', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
	}

	function productUrl(p) { return 'product.html?p=' + encodeURIComponent(p.slug); }

	var dataPromise = null;
	function data() {
		if (!dataPromise) {
			dataPromise = Promise.all([
				fetch('data/products.json', { cache: 'no-cache' }).then(function (r) { return r.json(); }),
				fetch('data/shop.json', { cache: 'no-cache' }).then(function (r) { return r.json(); })
			]).then(function (res) {
				var bySlug = {};
				res[0].products.forEach(function (p) {
					if (!p.images || !p.images.length) { p.images = ['assets/img/photo-coming-soon.svg']; }
					bySlug[p.slug] = p;
				});
				return { products: res[0].products, categories: res[0].categories, bySlug: bySlug, shop: res[1] };
			});
		}
		return dataPromise;
	}

	// Cart ----------------------------------------------------------------------------------

	function getCart() { var c = store(CART_KEY); return c && typeof c === 'object' ? c : {}; }
	function setCart(cart) { store(CART_KEY, cart); updateCount(); }
	function addToCart(slug, qty) {
		var cart = getCart();
		cart[slug] = Math.min((cart[slug] || 0) + qty, 20);
		setCart(cart);
	}
	function cartCount() {
		var cart = getCart();
		return Object.keys(cart).reduce(function (n, k) { return n + (cart[k] || 0); }, 0);
	}
	function updateCount() {
		var n = cartCount();
		document.querySelectorAll('.cart-count').forEach(function (el) {
			el.textContent = n;
			el.setAttribute('data-count', n);
		});
		document.querySelectorAll('.cart-link').forEach(function (el) {
			el.setAttribute('aria-label', 'Cart, ' + n + (n === 1 ? ' item' : ' items'));
		});
	}

	/** Cart lines priced from the catalogue (the server re-checks everything at checkout). */
	function pricedCart(d) {
		var cart = getCart();
		var lines = [];
		var subtotal = 0;
		Object.keys(cart).forEach(function (key) {
			var parts = key.split('|');
			var p = d.bySlug[parts[0]];
			var qty = cart[key];
			if (!p || !p.inStock || qty < 1) { return; }
			var opt = null;
			if (p.options) {
				opt = p.options.values.filter(function (v) { return v.value === parts[1]; })[0];
				if (!opt) { return; }
			}
			lines.push({ product: p, key: key, option: opt ? opt.value : '', image: (opt && opt.image) || p.images[0], qty: qty, total: p.price * qty });
			subtotal += p.price * qty;
		});
		var del = d.shop.delivery;
		var delivery = !lines.length ? 0 : (del.freeFrom && subtotal >= del.freeFrom ? 0 : del.flatRate);
		return { lines: lines, subtotal: subtotal, delivery: delivery, total: subtotal + delivery };
	}

	function toast(html) {
		var t = $('.toast');
		if (!t) {
			t = document.createElement('div');
			t.className = 'toast';
			t.setAttribute('role', 'status');
			document.body.appendChild(t);
		}
		t.innerHTML = html;
		requestAnimationFrame(function () { t.classList.add('is-on'); });
		clearTimeout(toast.timer);
		toast.timer = setTimeout(function () { t.classList.remove('is-on'); }, 4000);
	}

	// Product cards -------------------------------------------------------------------------

	function priceHtml(p) {
		var was = p.compareAtPrice && p.compareAtPrice > p.price ? '<s>' + money(p.compareAtPrice) + '</s>' : '';
		return was + money(p.price);
	}

	function card(p) {
		var badge = !p.inStock ? '<span class="badge badge--out">Sold out</span>'
			: (p.badge ? '<span class="badge">' + esc(p.badge) + '</span>' : '');
		var button = !p.inStock ? '<a class="btn btn--small btn--ghost" href="' + productUrl(p) + '">View</a>'
			: p.options ? '<a class="btn btn--small" href="' + productUrl(p) + '">Choose ' + esc(p.options.name.toLowerCase()) + '</a>'
			: '<button class="btn btn--small" type="button" data-add="' + esc(p.slug) + '">Add to cart</button>';
		return '<article class="card">' + badge +
			'<a class="thumb" href="' + productUrl(p) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(p.images[0]) +
			'" alt="" loading="lazy" width="600" height="600"></a>' +
			'<h3><a href="' + productUrl(p) + '">' + esc(p.name) + '</a></h3>' +
			'<p class="price">' + priceHtml(p) + '</p>' + button + '</article>';
	}

	function renderGrid(el, products, emptyText) {
		if (!el) { return; }
		el.innerHTML = products.length ? products.map(card).join('')
			: '<p class="empty">' + esc(emptyText || 'No products here yet. Check back soon!') + '</p>';
	}

	function newest(products) {
		return products.slice().sort(function (a, b) { return String(b.added).localeCompare(String(a.added)); });
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-add]');
		if (!btn) { return; }
		data().then(function (d) {
			var p = d.bySlug[btn.getAttribute('data-add')];
			if (!p) { return; }
			addToCart(p.slug, 1);
			toast(esc(p.name) + ' added to your cart. <a href="cart.html">View cart</a>');
		});
	});

	// Pages ---------------------------------------------------------------------------------

	var pages = {};

	pages.home = function (d) {
		renderReviews();
		renderGrid($('#new-arrivals'), newest(d.products).slice(0, 8));
		var featured = d.products.filter(function (p) { return p.featured; }).slice(0, 4);
		if (featured.length) { renderGrid($('#favourites'), featured); } else if ($('#favourites-section')) { $('#favourites-section').hidden = true; }

		var cats = d.categories.map(function (c) {
			var items = d.products.filter(function (p) { return p.category === c.slug; });
			return { cat: c, items: items };
		}).filter(function (x) { return x.items.length; });
		var catEl = $('#categories');
		if (catEl) {
			catEl.innerHTML = cats.map(function (x) {
				var img = x.cat.image || x.items[0].images[0];
				var url = 'shop.html?cat=' + encodeURIComponent(x.cat.slug);
				return '<article class="card cat-card"><a class="thumb" href="' + url + '" tabindex="-1" aria-hidden="true"><img src="' + esc(img) +
					'" alt="" loading="lazy"></a><h3><a href="' + url + '">' + esc(x.cat.name) + '</a></h3><p class="count">' +
					x.items.length + (x.items.length === 1 ? ' product' : ' products') + '</p></article>';
			}).join('');
		}
	};

	pages.shop = function (d) {
		var cat = params.get('cat') || '';
		var sort = params.get('sort') || 'new';
		var chips = $('#cat-chips');
		var used = d.categories.filter(function (c) { return d.products.some(function (p) { return p.category === c.slug; }); });
		chips.innerHTML = '<a class="chip" href="shop.html"' + (cat ? '' : ' aria-current="true"') + '>All</a>' +
			used.map(function (c) {
				return '<a class="chip" href="shop.html?cat=' + encodeURIComponent(c.slug) + '"' +
					(cat === c.slug ? ' aria-current="true"' : '') + '>' + esc(c.name) + '</a>';
			}).join('');

		var list = d.products.filter(function (p) { return !cat || p.category === cat; });
		if (sort === 'low') { list.sort(function (a, b) { return a.price - b.price; }); }
		else if (sort === 'high') { list.sort(function (a, b) { return b.price - a.price; }); }
		else { list = newest(list); }

		var current = d.categories.filter(function (c) { return c.slug === cat; })[0];
		if (current) {
			$('#shop-title').textContent = current.name;
			document.title = current.name + ' | BabyVibes';
		}
		var select = $('#sort');
		select.value = sort;
		select.addEventListener('change', function () {
			params.set('sort', select.value);
			location.search = params.toString();
		});
		renderGrid($('#shop-grid'), list);
	};

	pages.product = function (d) {
		var p = d.bySlug[params.get('p') || ''];
		var root = $('#product');
		if (!p) {
			root.innerHTML = '<div class="empty"><h1>Product not found</h1><p>It may have sold out or moved.</p><a class="btn" href="shop.html">Back to the shop</a></div>';
			return;
		}
		document.title = p.name + ' | BabyVibes';
		var meta = $('meta[name="description"]');
		if (meta) { meta.setAttribute('content', p.short); }
		var cat = d.categories.filter(function (c) { return c.slug === p.category; })[0];

		var thumbs = p.images.length > 1 ? '<div class="thumbs">' + p.images.map(function (src, i) {
			return '<button type="button" data-img="' + esc(src) + '"' + (i ? '' : ' aria-current="true"') +
				' aria-label="Show photo ' + (i + 1) + '"><img src="' + esc(src) + '" alt=""></button>';
		}).join('') + '</div>' : '';

		var list = function (items) {
			return '<ul>' + items.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>';
		};
		var info = [];
		if (p.features && p.features.length) { info.push(['What it does', list(p.features)]); }
		if (p.inTheBox && p.inTheBox.length) { info.push(["What's in the box", list(p.inTheBox)]); }
		if (p.age) { info.push(['Age and safety', '<p>' + esc(p.age) + '</p>']); }
		var del = d.shop.delivery;
		info.push(['Delivery and returns', '<p>' + esc(del.note) + ' Delivery is ' + money(del.flatRate) +
			(del.freeFrom ? ', or free on orders over ' + money(del.freeFrom) : '') +
			'. See our <a href="faq.html">FAQ &amp; Delivery</a> page for returns.</p>']);

		root.innerHTML =
			'<nav class="crumbs" aria-label="Breadcrumb"><a href="shop.html">Shop</a>' +
			(cat ? ' / <a href="shop.html?cat=' + encodeURIComponent(cat.slug) + '">' + esc(cat.name) + '</a>' : '') +
			' / <span>' + esc(p.name) + '</span></nav>' +
			'<div class="product"><div class="gallery"><div class="main"><img id="main-img" src="' + esc(p.images[0]) +
			'" alt="' + esc(p.name) + '" width="800" height="800"></div>' + thumbs + '</div>' +
			'<div><h1>' + esc(p.name) + '</h1><p class="price">' + priceHtml(p) + '</p>' +
			'<p class="lead">' + esc(p.short) + '</p>' +
			(p.inStock
				? '<p class="stock">In stock</p>' + (p.options ? '<fieldset class="options"><legend>' + esc(p.options.name) +
					': <span id="opt-label">choose one</span></legend><div class="opt-list">' + p.options.values.map(function (v) {
						return '<button type="button" class="opt" data-opt="' + esc(v.value) + '" aria-pressed="false">' +
							(v.swatch ? '<span class="swatch" style="background:' + esc(v.swatch) + '"></span>' : '') + esc(v.value) + '</button>';
					}).join('') + '</div><p class="err" id="opt-err" hidden>Please choose a ' + esc(p.options.name.toLowerCase()) + '.</p></fieldset>' : '') +
				'<div class="buy"><div class="qty" role="group" aria-label="Quantity">' +
				'<button type="button" data-step="-1" aria-label="One less">&minus;</button>' +
				'<input id="qty" type="number" min="1" max="20" value="1" inputmode="numeric" aria-label="Quantity">' +
				'<button type="button" data-step="1" aria-label="One more">+</button></div>' +
				'<button class="btn" type="button" id="add">Add to cart</button></div>'
				: '<p class="stock stock--out">Sold out for now. <a href="contact.html">Ask us</a> when it\'s back.</p>') +
			'<ul class="trust"><li>Delivery across South Africa</li><li>Secure payment with Yoco</li><li>Help on WhatsApp</li></ul>' +
			'<div class="info">' + '<details open><summary>About this product</summary><div>' + p.description + '</div></details>' +
			info.map(function (x) { return '<details><summary>' + esc(x[0]) + '</summary><div>' + x[1] + '</div></details>'; }).join('') +
			'</div></div></div>';

		var chosen = '';
		root.addEventListener('click', function (e) {
			var o = e.target.closest('[data-opt]');
			if (o) {
				chosen = o.getAttribute('data-opt');
				root.querySelectorAll('[data-opt]').forEach(function (b) { b.setAttribute('aria-pressed', b === o ? 'true' : 'false'); });
				$('#opt-label').textContent = chosen;
				$('#opt-err').hidden = true;
				var v = p.options.values.filter(function (x) { return x.value === chosen; })[0];
				if (v && v.image) {
					$('#main-img').src = v.image;
					root.querySelectorAll('[data-img]').forEach(function (b) {
						if (b.getAttribute('data-img') === v.image) { b.setAttribute('aria-current', 'true'); } else { b.removeAttribute('aria-current'); }
					});
				}
			}
			var t = e.target.closest('[data-img]');
			if (t) {
				$('#main-img').src = t.getAttribute('data-img');
				root.querySelectorAll('[data-img]').forEach(function (b) { b.removeAttribute('aria-current'); });
				t.setAttribute('aria-current', 'true');
			}
			var step = e.target.closest('[data-step]');
			if (step) {
				var q = $('#qty');
				q.value = Math.max(1, Math.min(20, (parseInt(q.value, 10) || 1) + Number(step.getAttribute('data-step'))));
			}
			if (e.target.closest('#add')) {
				var n = Math.max(1, Math.min(20, parseInt($('#qty').value, 10) || 1));
				if (p.options && !chosen) {
					$('#opt-err').hidden = false;
					$('.options').scrollIntoView({ behavior: 'smooth', block: 'center' });
					return;
				}
				addToCart(p.options ? p.slug + '|' + chosen : p.slug, n);
				toast(n + ' x ' + esc(p.name) + (chosen ? ' (' + esc(chosen) + ')' : '') + ' added to your cart. <a href="cart.html">View cart</a>');
			}
		});

		var related = d.products.filter(function (x) { return x.slug !== p.slug && x.category === p.category; });
		if (related.length < 4) {
			related = related.concat(d.products.filter(function (x) { return x.slug !== p.slug && x.category !== p.category; }));
		}
		if (related.length) {
			$('#related-section').hidden = false;
			renderGrid($('#related'), related.slice(0, 4));
		}
	};

	function summaryHtml(d, c, withLines) {
		var del = d.shop.delivery;
		var bar = '';
		if (del.freeFrom && c.subtotal > 0 && c.subtotal < del.freeFrom) {
			bar = '<p class="free-bar">Add ' + money(del.freeFrom - c.subtotal) + ' more for free delivery.</p>';
		} else if (del.freeFrom && c.subtotal >= del.freeFrom) {
			bar = '<p class="free-bar">You get free delivery!</p>';
		}
		var mini = withLines ? '<ul class="mini">' + c.lines.map(function (l) {
			return '<li><span>' + l.qty + ' x ' + esc(l.product.name) + (l.option ? ' (' + esc(l.option) + ')' : '') + '</span><span>' + money(l.total) + '</span></li>';
		}).join('') + '</ul>' : '';
		return '<h2>Order summary</h2>' + mini + bar + '<dl><dt>Subtotal</dt><dd>' + money(c.subtotal) + '</dd>' +
			'<dt>Delivery</dt><dd>' + (c.delivery ? money(c.delivery) : 'Free') + '</dd>' +
			'<dt class="grand">Total</dt><dd class="grand">' + money(c.total) + '</dd></dl>';
	}

	pages.cart = function (d) {
		var root = $('#cart');
		function draw() {
			var c = pricedCart(d);
			if (!c.lines.length) {
				root.innerHTML = '<div class="empty"><p class="lead">Your cart is empty.</p><a class="btn" href="shop.html">Start shopping</a></div>';
				return;
			}
			root.innerHTML = '<div class="cart-layout"><div class="cart-lines">' + c.lines.map(function (l) {
				var p = l.product;
				var k = esc(l.key);
				return '<div class="line"><img src="' + esc(l.image) + '" alt="">' +
					'<div><h3><a href="' + productUrl(p) + '">' + esc(p.name) + '</a></h3>' +
					(l.option ? '<p class="unit">' + esc(p.options.name) + ': <strong>' + esc(l.option) + '</strong></p>' : '') +
					'<p class="unit">' + money(p.price) + ' each</p></div>' +
					'<div class="end"><div class="qty qty--small" role="group" aria-label="Quantity of ' + esc(p.name) + '">' +
					'<button type="button" data-qty="' + k + '" data-step="-1" aria-label="One less">&minus;</button>' +
					'<input type="number" value="' + l.qty + '" min="1" max="20" data-set="' + k + '" aria-label="Quantity">' +
					'<button type="button" data-qty="' + k + '" data-step="1" aria-label="One more">+</button></div>' +
					'<span class="total">' + money(l.total) + '</span>' +
					'<button class="link-btn" type="button" data-remove="' + k + '">Remove</button></div></div>';
			}).join('') + '</div><aside class="summary">' + summaryHtml(d, c, false) +
				'<a class="btn btn--block" href="checkout.html">Checkout</a>' +
				'<p class="secure">' + LOCK + ' Secure payment with Yoco</p></aside></div>';
		}
		root.addEventListener('click', function (e) {
			var cart = getCart();
			var q = e.target.closest('[data-qty]');
			var r = e.target.closest('[data-remove]');
			if (q) {
				var slug = q.getAttribute('data-qty');
				cart[slug] = Math.min(20, (cart[slug] || 0) + Number(q.getAttribute('data-step')));
				if (cart[slug] < 1) { delete cart[slug]; }
			} else if (r) {
				delete cart[r.getAttribute('data-remove')];
			} else { return; }
			setCart(cart);
			draw();
		});
		root.addEventListener('change', function (e) {
			var s = e.target.getAttribute('data-set');
			if (!s) { return; }
			var cart = getCart();
			var n = parseInt(e.target.value, 10) || 0;
			if (n < 1) { delete cart[s]; } else { cart[s] = Math.min(20, n); }
			setCart(cart);
			draw();
		});
		draw();
	};

	pages.checkout = function (d) {
		var c = pricedCart(d);
		var form = $('#checkout-form');
		var alert = $('#checkout-alert');
		if (!c.lines.length) {
			$('#checkout').innerHTML = '<div class="empty"><p class="lead">Your cart is empty.</p><a class="btn" href="shop.html">Start shopping</a></div>';
			return;
		}
		$('#checkout-summary').innerHTML = summaryHtml(d, c, true) + '<p class="note"><a href="cart.html">Edit cart</a></p>';
		if (params.get('cancelled')) { showAlert('Payment cancelled. Your cart is saved, so you can try again whenever you\'re ready.', 'info'); }
		if (params.get('failed')) { showAlert('The payment didn\'t go through and you haven\'t been charged. Please try again or use another card.', 'error'); }

		var saved = store(CUSTOMER_KEY) || {};
		Object.keys(saved).forEach(function (k) { if (form.elements[k] && !form.elements[k].value) { form.elements[k].value = saved[k]; } });

		var box = $('#account-box');
		me().then(function (cust) {
			if (cust) {
				['firstName', 'lastName', 'email', 'phone', 'address1', 'address2', 'suburb', 'city', 'province', 'postcode'].forEach(function (k) {
					if (cust[k] && form.elements[k]) { form.elements[k].value = cust[k]; }
				});
				box.innerHTML = '<p class="signed-in">Signed in as <strong>' + esc(cust.firstName + ' ' + cust.lastName) +
					'</strong>. This order will be saved to <a href="account.html">your account</a>.</p>';
				return;
			}
			box.innerHTML = '<p class="hint">Have an account? <a href="account.html?next=checkout.html">Log in</a> for faster checkout.</p>' +
				'<label class="check"><input type="checkbox" name="createAccount" id="createAccount"> Create an account to track this order and check out faster next time</label>' +
				'<div id="account-fields" hidden><div class="field"><label for="password">Choose a password <span class="hint">at least 8 characters</span></label>' +
				'<input id="password" name="password" type="password" minlength="8" autocomplete="new-password"></div>' +
				'<label class="check"><input type="checkbox" name="marketing"> Email me about new products and specials (you can stop any time)</label></div>';
			$('#createAccount').addEventListener('change', function () {
				$('#account-fields').hidden = !this.checked;
				form.elements.password.required = this.checked;
			});
		});

		function showAlert(msg, kind) {
			alert.className = 'alert alert--' + (kind || 'error');
			alert.textContent = msg;
			alert.hidden = false;
			alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			form.querySelectorAll('.field').forEach(function (f) {
				f.classList.remove('has-error');
				var m = f.querySelector('.err');
				if (m) { m.remove(); }
			});
			alert.hidden = true;
			if (!form.reportValidity()) { return; }

			var customer = {};
			['firstName', 'lastName', 'email', 'phone', 'address1', 'address2', 'suburb', 'city', 'province', 'postcode', 'notes']
				.forEach(function (k) { customer[k] = form.elements[k].value.trim(); });
			var keep = Object.assign({}, customer);
			delete keep.notes;
			store(CUSTOMER_KEY, keep);

			var btn = $('#pay');
			btn.disabled = true;
			btn.textContent = 'Opening secure payment...';
			var create = form.elements.createAccount && form.elements.createAccount.checked;
			post('api/checkout.php', {
				cart: getCart(), customer: customer, website: form.elements.website.value,
				createAccount: !!create, password: create ? form.elements.password.value : '',
				marketing: !!(create && form.elements.marketing.checked)
			}).then(function (res) {
				if (res.redirectUrl) {
					location.href = res.redirectUrl;
					return;
				}
				btn.disabled = false;
				btn.textContent = 'Pay ' + money(c.total) + ' securely';
				if (res.fields) {
					Object.keys(res.fields).forEach(function (k) {
						var input = form.elements[k];
						var f = input && input.closest('.field');
						if (f) {
							f.classList.add('has-error');
							f.insertAdjacentHTML('beforeend', '<p class="err">' + esc(res.fields[k]) + '</p>');
						}
					});
				}
				var msg = res.error || 'Something went wrong. Please try again.';
				if (res.fields && res.fields.cart) { msg = res.fields.cart; }
				showAlert(msg);
			});
		});
		$('#pay').textContent = 'Pay ' + money(c.total) + ' securely';
	};

	pages.thanks = function () {
		var root = $('#thanks');
		var id = params.get('order');
		var t = params.get('t');
		if (!id || !t) { return; }
		var tries = 0;
		function check() {
			fetch('api/order-status.php?order=' + encodeURIComponent(id) + '&t=' + encodeURIComponent(t), { cache: 'no-store' })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (o) {
					if (!o) { return; }
					if (o.status === 'paid') {
						setCart({});
						root.innerHTML = '<h1>Thank you, ' + esc(o.firstName) + '!</h1>' +
							'<p class="lead">Your payment went through and your order <strong>' + esc(o.id) + '</strong> is confirmed. ' +
							'We\'ve emailed you a receipt and we\'ll send your tracking number when it ships.</p>' +
							'<div class="summary" style="position:static;text-align:left;max-width:440px;margin:24px auto"><ul class="mini">' +
							o.lines.map(function (l) { return '<li><span>' + l.qty + ' x ' + esc(l.name) + '</span><span>' + cents(l.total) + '</span></li>'; }).join('') +
							'<li><span>Delivery</span><span>' + (o.delivery ? cents(o.delivery) : 'Free') + '</span></li>' +
							'<li><strong>Total</strong><strong>' + cents(o.total) + '</strong></li></ul></div>' +
							'<div class="btn-row" style="justify-content:center"><a class="btn" href="track.html?order=' + encodeURIComponent(o.id) + '">Track your order</a>' +
							'<a class="btn btn--ghost" href="shop.html">Keep shopping</a></div>';
					} else if (o.status === 'pending' && tries++ < 10) {
						root.querySelector('.lead').textContent = 'We\'re confirming your payment with Yoco. This usually takes a few seconds...';
						setTimeout(check, 3000);
					} else if (o.status === 'pending') {
						root.querySelector('.lead').innerHTML = 'Your payment is still being confirmed. You\'ll get an email as soon as it is. ' +
							'If you were charged and don\'t hear from us within an hour, please <a href="contact.html">contact us</a> with order number <strong>' + esc(o.id) + '</strong>.';
					}
				});
		}
		check();
	};


	// Reviews -------------------------------------------------------------------------------

	function starsHtml(n) {
		var out = '<span class="star-row" aria-label="' + n + ' out of 5 stars">';
		for (var i = 1; i <= 5; i++) { out += '<span class="' + (i <= n ? 'on' : '') + '" aria-hidden="true">&#9733;</span>'; }
		return out + '</span>';
	}

	function loadReviews() {
		return fetch('api/reviews.php').then(function (r) { return r.json(); })
			.then(function (d) { return (d && d.reviews) || []; }).catch(function () { return []; });
	}

	function renderReviews() {
		var root = $('#reviews');
		if (!root) { return; }
		loadReviews().then(function (list) {
			list = list.filter(function (r) { return r.rating >= 1 && r.text; })
				.sort(function (a, b) { return String(b.date).localeCompare(String(a.date)); });
			if (!list.length) {
				$('#reviews-summary').textContent = '';
				root.innerHTML = '<div class="review-empty"><p class="lead">We\'re a brand-new shop, so our first reviews are on their way.</p>' +
					'<p>Every review here comes from a real customer with a verified order.</p></div>';
				return;
			}
			var avg = function (key) {
				var xs = list.filter(function (r) { return r[key]; });
				return xs.length ? xs.reduce(function (t, r) { return t + r[key]; }, 0) / xs.length : 0;
			};
			var overall = avg('rating');
			var parts = [['Professionalism', avg('professionalism')], ['Convenience', avg('convenience')]]
				.filter(function (x) { return x[1]; })
				.map(function (x) { return x[0] + ' ' + x[1].toFixed(1) + '/5'; });
			$('#reviews-summary').innerHTML = starsHtml(Math.round(overall)) + ' <strong>' + overall.toFixed(1) + ' out of 5</strong> from ' +
				list.length + (list.length === 1 ? ' verified review' : ' verified reviews') + (parts.length ? '<br>' + parts.join(' &middot; ') : '');
			root.innerHTML = list.slice(0, 6).map(function (r) {
				return '<figure class="review">' + starsHtml(r.rating) + '<blockquote>' + esc(r.text) + '</blockquote>' +
					'<figcaption><strong>' + esc(r.name) + '</strong>' + (r.location ? ', ' + esc(r.location) : '') +
					(r.verified ? ' <span class="verified">Verified buyer</span>' : '') + '</figcaption></figure>';
			}).join('');
		});
	}

	pages.review = function () {
		var form = $('#review-form');
		var alert = $('#review-alert');
		if (params.get('order')) { form.elements.order.value = params.get('order'); }
		var saved = store(CUSTOMER_KEY) || {};
		if (saved.email) { form.elements.email.value = saved.email; }
		if (saved.firstName && saved.lastName) { form.elements.name.value = saved.firstName + ' ' + saved.lastName.charAt(0) + '.'; }
		if (saved.city) { form.elements.location.value = saved.city; }

		var values = {};
		form.querySelectorAll('.stars').forEach(function (group) {
			var name = group.getAttribute('data-name');
			values[name] = 0;
			for (var i = 1; i <= 5; i++) {
				group.insertAdjacentHTML('beforeend', '<button type="button" role="radio" aria-checked="false" data-v="' + i +
					'" aria-label="' + i + (i === 1 ? ' star' : ' stars') + '">&#9733;</button>');
			}
			group.addEventListener('click', function (e) {
				var b = e.target.closest('[data-v]');
				if (!b) { return; }
				values[name] = Number(b.getAttribute('data-v'));
				group.querySelectorAll('button').forEach(function (x) {
					var on = Number(x.getAttribute('data-v')) <= values[name];
					x.classList.toggle('on', on);
					x.setAttribute('aria-checked', Number(x.getAttribute('data-v')) === values[name] ? 'true' : 'false');
				});
			});
		});

		function showAlert(msg, kind) {
			alert.className = 'alert alert--' + (kind || 'error');
			alert.textContent = msg;
			alert.hidden = false;
			alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			form.querySelectorAll('.field').forEach(function (f) {
				f.classList.remove('has-error');
				var m = f.querySelector('.err');
				if (m) { m.remove(); }
			});
			alert.hidden = true;
			if (!form.reportValidity()) { return; }
			var body = {
				order: form.elements.order.value.trim(), email: form.elements.email.value.trim(),
				name: form.elements.name.value.trim(), location: form.elements.location.value.trim(),
				text: form.elements.text.value.trim(), website: form.elements.website.value,
				rating: values.rating, professionalism: values.professionalism, convenience: values.convenience
			};
			if (!body.rating) {
				showAlert('Please choose an overall star rating.');
				return;
			}
			var btn = $('#send-review');
			btn.disabled = true;
			post('api/review.php', body)
				.then(function (res) {
					btn.disabled = false;
					if (res.ok) {
						$('#review-wrap').innerHTML = '<div class="prose center"><h2>Thank you!</h2><p class="lead">Your review has been sent. ' +
							'It will appear on the site once we\'ve checked it.</p><a class="btn" href="shop.html">Back to the shop</a></div>';
						return;
					}
					if (res.fields) {
						Object.keys(res.fields).forEach(function (k) {
							var input = form.elements[k] || form.querySelector('[data-name="' + k + '"]');
							var f = input && input.closest('.field');
							if (f) {
								f.classList.add('has-error');
								f.insertAdjacentHTML('beforeend', '<p class="err">' + esc(res.fields[k]) + '</p>');
							}
						});
					}
					showAlert(res.error || 'Something went wrong. Please try again.');
				})
				.catch(function () {
					btn.disabled = false;
					showAlert('We couldn\'t reach the shop. Check your internet connection and try again.');
				});
		});
	};


	// Accounts, tracking ----------------------------------------------------------------------

	var STEPS = [['paid', 'Paid'], ['shipped', 'Shipped'], ['delivered', 'Delivered']];

	/** An order's progress, history and courier details (account page and tracking page). */
	function orderDetailHtml(o) {
		var stopped = ['cancelled', 'refunded'].indexOf(o.status) >= 0;
		var reached = { pending: 0, amount_mismatch: 0, checkout_failed: 0, paid: 1, shipped: 2, delivered: 3 }[o.status] || 0;
		var steps = stopped ? '<p class="alert alert--info">' + esc(o.statusLabel) + '</p>' :
			'<ol class="steps" aria-label="Order progress">' + '<li class="done">Ordered</li>' + STEPS.map(function (st, i) {
				return '<li class="' + (reached > i ? 'done' : '') + (reached === i + 1 ? ' now' : '') + '">' + st[1] + '</li>';
			}).join('') + '</ol>';
		var tr = o.tracking ? '<div class="tracking"><strong>' + esc(o.tracking.courier || 'Courier') + '</strong> tracking number <strong>' + esc(o.tracking.number) + '</strong>' +
			(o.tracking.url ? ' <a class="btn btn--small" href="' + esc(o.tracking.url) + '" target="_blank" rel="noopener">Track with the courier</a>' : '') + '</div>' : '';
		var events = (o.events || []).slice().reverse().map(function (e) {
			return '<li><time>' + esc(dateTime(e.created_at)) + '</time> ' + esc(e.message) + '</li>';
		}).join('');
		var lines = o.lines.map(function (l) {
			return '<li><span>' + l.qty + ' x ' + esc(l.name) + '</span><span>' + cents(l.total) + '</span></li>';
		}).join('') + '<li><span>Delivery</span><span>' + (o.delivery ? cents(o.delivery) : 'Free') + '</span></li>' +
			'<li><strong>Total</strong><strong>' + cents(o.total) + '</strong></li>';
		return steps + tr + '<div class="order-cols"><div><h3>Updates</h3><ul class="events">' + events + '</ul></div>' +
			'<div><h3>Items</h3><ul class="mini">' + lines + '</ul>' +
			(o.address ? '<h3>Delivering to</h3><p class="addr">' + esc(o.address).replace(/\n/g, '<br>') + '</p>' : '') + '</div></div>';
	}

	function updateAccountLink() {
		me().then(function (c) {
			document.querySelectorAll('.account-link').forEach(function (a) {
				a.setAttribute('aria-label', c ? 'My account (' + c.firstName + ')' : 'Log in or create an account');
				a.classList.toggle('is-in', !!c);
			});
		});
	}

	pages.account = function () {
		var root = $('#account');
		var next = params.get('next');
		if (next && !/^[\w-]+\.html(\?[\w=&%.-]*)?$/.test(next)) { next = ''; }

		function signedOut(mode) {
			root.innerHTML = $('#tpl-auth').innerHTML;
			var login = $('#login-form');
			var reg = $('#register-form');
			var forgot = $('#forgot-form');
			function show(which) {
				login.closest('.auth-card').hidden = which === 'forgot';
				forgot.closest('.auth-card').hidden = which !== 'forgot';
			}
			show(mode);
			root.addEventListener('click', function (e) {
				if (e.target.closest('[data-forgot]')) { e.preventDefault(); show('forgot'); forgot.elements.email.value = login.elements.email.value; forgot.elements.email.focus(); }
				if (e.target.closest('[data-login]')) { e.preventDefault(); show('login'); }
			});
			function done(res) {
				me(true);
				updateAccountLink();
				if (next) { location.href = next; return; }
				signedIn(res.customer);
			}
			login.addEventListener('submit', function (e) {
				e.preventDefault();
				clearErrors(login);
				var btn = login.querySelector('button');
				btn.disabled = true;
				post('api/account.php?action=login', values(login, ['email', 'password'])).then(function (res) {
					btn.disabled = false;
					if (res.customer) { done(res); return; }
					say($('#login-alert'), res.error);
				});
			});
			reg.addEventListener('submit', function (e) {
				e.preventDefault();
				clearErrors(reg);
				if (!reg.reportValidity()) { return; }
				var btn = reg.querySelector('button');
				btn.disabled = true;
				post('api/account.php?action=register', values(reg, ['firstName', 'lastName', 'email', 'password', 'marketing', 'website'])).then(function (res) {
					btn.disabled = false;
					if (res.customer) { done(res); return; }
					showErrors(reg, res.fields);
					say($('#register-alert'), res.error);
				});
			});
			forgot.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!forgot.reportValidity()) { return; }
				post('api/account.php?action=forgot', values(forgot, ['email'])).then(function (res) {
					if (res.ok) {
						say($('#forgot-alert'), 'If there\'s an account with that email, we\'ve sent a link to reset your password. Check your inbox (and spam folder).', 'info');
					} else {
						say($('#forgot-alert'), res.error);
					}
				});
			});
		}

		function signedIn(c) {
			root.innerHTML = $('#tpl-dash').innerHTML;
			$('#hello').textContent = 'Hi, ' + c.firstName + '!';
			var form = $('#details-form');
			Object.keys(c).forEach(function (k) {
				var el = form.elements[k];
				if (!el) { return; }
				if (el.type === 'checkbox') { el.checked = !!c[k]; } else { el.value = c[k] || ''; }
			});
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				clearErrors(form);
				var body = values(form, ['firstName', 'lastName', 'phone', 'address1', 'address2', 'suburb', 'city', 'province', 'postcode', 'marketing']);
				post('api/account.php?action=update', body).then(function (res) {
					if (res.customer) { say($('#details-alert'), 'Your details are saved.', 'info'); me(true); return; }
					showErrors(form, res.fields);
					say($('#details-alert'), res.error);
				});
			});
			var pw = $('#password-form');
			pw.addEventListener('submit', function (e) {
				e.preventDefault();
				clearErrors(pw);
				if (!pw.reportValidity()) { return; }
				var body = values(form, ['firstName', 'lastName', 'phone', 'address1', 'address2', 'suburb', 'city', 'province', 'postcode', 'marketing']);
				body.currentPassword = pw.elements.currentPassword.value;
				body.newPassword = pw.elements.newPassword.value;
				post('api/account.php?action=update', body).then(function (res) {
					if (res.customer) { pw.reset(); say($('#password-alert'), 'Your password is changed.', 'info'); return; }
					showErrors(pw, res.fields);
					say($('#password-alert'), res.error);
				});
			});
			$('#logout').addEventListener('click', function () {
				post('api/account.php?action=logout').then(function () { me(true); updateAccountLink(); signedOut('login'); window.scrollTo(0, 0); });
			});
			get('api/account.php?action=orders').then(function (res) {
				var list = $('#orders');
				var orders = res.orders || [];
				if (!orders.length) {
					list.innerHTML = '<div class="empty"><p>No orders yet. Orders you place while logged in will show up here.</p><a class="btn" href="shop.html">Start shopping</a></div>';
					return;
				}
				list.innerHTML = orders.map(function (o, i) {
					return '<details class="order-card"' + (i === 0 ? ' open' : '') + '><summary><span><strong>' + esc(o.id) + '</strong><br><span class="hint">' +
						esc(date(o.created)) + ' &middot; ' + cents(o.total) + '</span></span><span class="badge-st st-' + esc(o.status) + '">' + esc(o.statusLabel) + '</span></summary>' +
						'<div>' + orderDetailHtml(o) + '</div></details>';
				}).join('');
			});
		}

		me().then(function (c) { if (c) { signedIn(c); } else { signedOut('login'); } });
	};

	pages.reset = function () {
		var form = $('#reset-form');
		var token = params.get('token') || '';
		if (!token) { say($('#reset-alert'), 'This link is incomplete. Open the link from the email again, or ask for a new one on the login page.'); }
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			clearErrors(form);
			if (!form.reportValidity()) { return; }
			if (form.elements.password.value !== form.elements.password2.value) {
				showErrors(form, { password2: 'The two passwords don\'t match.' });
				return;
			}
			post('api/account.php?action=reset', { token: token, password: form.elements.password.value }).then(function (res) {
				if (res.customer) {
					$('#reset-wrap').innerHTML = '<div class="prose center"><h2>Password changed</h2><p>You\'re logged in.</p><a class="btn" href="account.html">Go to my account</a></div>';
					return;
				}
				showErrors(form, res.fields);
				say($('#reset-alert'), res.error);
			});
		});
	};

	pages.track = function () {
		var form = $('#track-form');
		var out = $('#track-result');
		if (params.get('order')) { form.elements.order.value = params.get('order'); }
		var saved = store(CUSTOMER_KEY) || {};
		if (saved.email) { form.elements.email.value = saved.email; }
		me().then(function (c) { if (c && !form.elements.email.value) { form.elements.email.value = c.email; } });
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!form.reportValidity()) { return; }
			var btn = form.querySelector('button');
			btn.disabled = true;
			post('api/track.php', values(form, ['order', 'email'])).then(function (o) {
				btn.disabled = false;
				if (!o.id) { out.innerHTML = ''; say($('#track-alert'), o.error || 'Order not found.'); return; }
				$('#track-alert').hidden = true;
				out.innerHTML = '<div class="prose"><div class="order-head"><h2>Order ' + esc(o.id) + '</h2><span class="badge-st st-' + esc(o.status) + '">' + esc(o.statusLabel) + '</span></div>' +
					'<p class="hint">Placed ' + esc(date(o.created)) + '</p>' + orderDetailHtml(o) + '</div>';
				out.scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
		});
		if (form.elements.order.value && form.elements.email.value) { form.requestSubmit ? form.requestSubmit() : null; }
	};

	var LOCK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5zm-3 8V7a3 3 0 1 1 6 0v3H9z"/></svg>';


	// Black Friday (or any promotion in data/shop.json): countdown to the start, then to the end.

	function promo(d) {
		var pr = d.shop.promo;
		var bar = $('[data-promo-bar]');
		var section = $('[data-promo-section]');
		if (!pr) { return; }
		var start = Date.parse(pr.start);
		var end = Date.parse(pr.end);
		var boxes = document.querySelectorAll('[data-countdown]');
		var labels = document.querySelectorAll('[data-promo-label]');
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };

		function tick() {
			var now = Date.now();
			if (isNaN(start) || isNaN(end) || now >= end) {
				if (bar) { bar.textContent = bar.getAttribute('data-fallback') || ''; bar.hidden = !bar.textContent; }
				if (section) { section.hidden = true; }
				clearInterval(timer);
				return;
			}
			var live = now >= start;
			var left = Math.max(0, Math.floor(((live ? end : start) - now) / 1000));
			var dd = Math.floor(left / 86400);
			var hh = Math.floor(left % 86400 / 3600);
			var mm = Math.floor(left % 3600 / 60);
			var ss = left % 60;
			labels.forEach(function (el) {
				el.textContent = el.closest('[data-promo-bar]') ? (live ? ' ends in' : ' starts in') : (live ? 'Ends in' : 'Starts in');
			});
			var head = $('[data-promo-headline]');
			if (head && live && pr.liveHeadline) { head.textContent = pr.liveHeadline; }
			boxes.forEach(function (el) {
				if (el.getAttribute('data-countdown') === 'compact') {
					el.textContent = (dd ? dd + 'd ' : '') + pad(hh) + 'h ' + pad(mm) + 'm ' + pad(ss) + 's';
				} else {
					el.innerHTML = [[dd, 'Days'], [hh, 'Hours'], [mm, 'Mins'], [ss, 'Secs']].map(function (u, i) {
						return '<span class="unit"><b>' + (i ? pad(u[0]) : u[0]) + '</b><small>' + u[1] + '</small></span>';
					}).join('');
					el.setAttribute('aria-label', dd + ' days, ' + hh + ' hours and ' + mm + ' minutes');
				}
			});
			if (section) { section.hidden = false; }
		}
		var timer = setInterval(tick, 1000);
		tick();
	}

	// Start ---------------------------------------------------------------------------------

	var toggle = $('.menu-toggle');
	if (toggle) {
		toggle.addEventListener('click', function () {
			var open = $('#site-nav').classList.toggle('is-open');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	}
	updateCount();
	updateAccountLink();
	data().then(promo).catch(function () {});
	window.addEventListener('storage', function (e) { if (e.key === CART_KEY) { updateCount(); } });

	if (pages[page]) {
		data().then(pages[page]).catch(function (err) {
			console.error(err);
			var main = $('main');
			if (main) { main.insertAdjacentHTML('afterbegin', '<div class="wrap"><p class="alert alert--error">Sorry, the shop didn\'t load properly. Please refresh the page.</p></div>'); }
		});
	}
})();
