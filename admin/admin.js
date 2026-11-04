/* BabyVibes admin: chart tooltips, copy buttons and confirmations. */
(function () {
	'use strict';
	var tip = null;
	function show(el, x, y) {
		if (!tip) { tip = document.createElement('div'); tip.className = 'tip'; tip.setAttribute('role', 'status'); document.body.appendChild(tip); }
		tip.textContent = el.getAttribute('data-tip');
		tip.style.left = x + 'px';
		tip.style.top = y + 'px';
		tip.hidden = false;
	}
	function hide() { if (tip) { tip.hidden = true; } }
	document.querySelectorAll('.chart .day').forEach(function (g) {
		// Native SVG <title> tooltips would double up with ours.
		var t = g.querySelector('title');
		if (t) { g.setAttribute('aria-label', t.textContent); t.remove(); }
		g.addEventListener('mousemove', function (e) { show(g, e.clientX, e.clientY); });
		g.addEventListener('mouseleave', hide);
		g.addEventListener('focus', function () { var r = g.getBoundingClientRect(); show(g, r.left + r.width / 2, r.top + 20); });
		g.addEventListener('blur', hide);
	});
	document.querySelectorAll('[data-copy]').forEach(function (b) {
		b.addEventListener('click', function () {
			var text = b.getAttribute('data-copy');
			(navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
				var old = b.textContent; b.textContent = 'Copied!'; setTimeout(function () { b.textContent = old; }, 1500);
			}).catch(function () { window.prompt('Copy the address:', text); });
		});
	});
	document.querySelectorAll('form[data-confirm]').forEach(function (f) {
		f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); } });
	});
})();
