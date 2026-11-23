/* WD Performans Doktoru – Web Danışmanı */
(function () {
	'use strict';

	var root = document.querySelector('.wdpd');
	if (!root || !window.WDPD) return;
	var cfg = window.WDPD;

	function $(sel, ctx) { return (ctx || root).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

	/* ---------- Tema ---------- */
	var THEME_KEY = 'wdpd-theme';
	try {
		var saved = window.localStorage.getItem(THEME_KEY);
		if (saved === 'dark' || saved === 'light') root.setAttribute('data-theme', saved);
	} catch (e) {}

	var themeBtn = $('[data-wdpd-theme]');
	if (themeBtn) {
		themeBtn.addEventListener('click', function () {
			var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
			root.setAttribute('data-theme', next);
			try { window.localStorage.setItem(THEME_KEY, next); } catch (e) {}
		});
	}

	/* ---------- Bildirim ---------- */
	function toast(msg, isError) {
		var wrap = $('.wdpd-toasts');
		if (!wrap) return;
		var el = document.createElement('div');
		el.className = 'wdpd-toast' + (isError ? ' is-error' : '');
		el.textContent = msg;
		wrap.appendChild(el);
		setTimeout(function () { el.remove(); }, isError ? 7000 : 4200);
	}

	/* ---------- AJAX ---------- */
	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (v && typeof v === 'object') {
				Object.keys(v).forEach(function (kk) { fd.append(k + '[' + kk + ']', v[kk]); });
			} else {
				fd.append(k, v);
			}
		});
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) {
				return r.text().then(function (t) {
					var j;
					try { j = JSON.parse(t); } catch (e) {
						throw new Error(r.ok ? 'Sunucudan beklenmeyen yanıt geldi. Bir eklenti çıktıyı bozuyor olabilir.' : 'Sunucu hatası (HTTP ' + r.status + '). İşlem zaman aşımına uğramış olabilir; tekrar deneyin.');
					}
					if (!j.success) throw new Error((j.data && j.data.message) || 'İşlem tamamlanamadı.');
					return j.data || {};
				});
			});
	}

	/* ---------- Sekmeler ---------- */
	var tabs = $$('.wdpd-tab');
	var panels = $$('.wdpd-panel');

	function showTab(name, opts) {
		opts = opts || {};
		if (!panels.some(function (p) { return p.dataset.panel === name; })) name = 'overview';
		tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.tab === name ? 'true' : 'false'); });
		panels.forEach(function (p) { p.hidden = p.dataset.panel !== name; });
		if (history.replaceState) history.replaceState(null, '', '#' + name);
		if (opts.scroll !== false) window.scrollTo({ top: 0 });
	}

	tabs.forEach(function (t) {
		t.addEventListener('click', function (e) {
			e.preventDefault();
			showTab(t.dataset.tab);
		});
	});
	showTab((location.hash || '#overview').slice(1), { scroll: false });

	root.addEventListener('click', function (e) {
		var g = e.target.closest('[data-wdpd-goto]');
		if (!g) return;
		e.preventDefault();
		showTab(g.dataset.wdpdGoto);
		var target = null;
		if (g.dataset.open) {
			target = document.getElementById('wdpd-f-' + g.dataset.open);
			if (target) target.open = true;
		} else if (g.dataset.cat) {
			target = document.getElementById('wdpd-cat-' + g.dataset.cat);
		}
		if (target) setTimeout(function () { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 30);
	});

	/* ---------- Modal ---------- */
	var modal = $('.wdpd-modal');
	var scanView = $('[data-modal-scan]');
	var confirmView = $('[data-modal-confirm]');

	function openModal(view) {
		scanView.hidden = view !== 'scan';
		confirmView.hidden = view !== 'confirm';
		modal.hidden = false;
	}
	function closeModal() { modal.hidden = true; }

	function confirmBox(text) {
		return new Promise(function (resolve) {
			if (!text) return resolve(true);
			$('[data-confirm-text]').textContent = text;
			openModal('confirm');
			var yes = $('[data-confirm-yes]');
			var no = $('[data-confirm-no]');
			yes.focus();
			function done(v) {
				yes.removeEventListener('click', onYes);
				no.removeEventListener('click', onNo);
				document.removeEventListener('keydown', onKey);
				closeModal();
				resolve(v);
			}
			function onYes() { done(true); }
			function onNo() { done(false); }
			function onKey(ev) { if (ev.key === 'Escape') done(false); }
			yes.addEventListener('click', onYes);
			no.addEventListener('click', onNo);
			document.addEventListener('keydown', onKey);
		});
	}

	/* ---------- Tarama ---------- */
	var scanning = false;

	function runScan() {
		if (scanning) return;
		scanning = true;
		var list = $('[data-steps]');
		list.innerHTML = '';
		cfg.categories.forEach(function (c) {
			var li = document.createElement('li');
			li.dataset.cat = c.key;
			li.innerHTML = '<span class="st"></span><div><strong></strong><small></small></div><em></em>';
			li.querySelector('strong').textContent = c.label;
			li.querySelector('small').textContent = c.desc;
			list.appendChild(li);
		});
		openModal('scan');

		var chain = post('wdpd_scan_start');
		cfg.categories.forEach(function (c) {
			chain = chain.then(function () {
				var li = list.querySelector('[data-cat="' + c.key + '"]');
				li.classList.add('is-run');
				return post('wdpd_scan_step', { cat: c.key }).then(function (r) {
					li.classList.remove('is-run');
					li.classList.add('is-done');
					var em = li.querySelector('em');
					em.textContent = r.issues ? r.issues + ' sorun' : 'Temiz';
					if (r.issues) em.classList.add('has');
				}, function (err) {
					li.classList.remove('is-run');
					li.classList.add('is-err');
					li.querySelector('em').textContent = 'Atlandı';
					toast(c.label + ': ' + err.message, true);
				});
			});
		});

		chain
			.then(function () { return post('wdpd_scan_finish'); })
			.then(function () {
				$('#wdpd-modal-title').textContent = 'Tarama tamamlandı';
				setTimeout(function () {
					if (history.replaceState) history.replaceState(null, '', '#overview');
					location.reload();
				}, 700);
			})
			.catch(function (err) {
				scanning = false;
				closeModal();
				toast(err.message, true);
			});
	}

	$$('[data-wdpd-scan]').forEach(function (b) { b.addEventListener('click', runScan); });

	/* ---------- Tek tık çözümler ---------- */
	function fmt(n) { return Number(n).toLocaleString('tr-TR'); }

	function runFix(btn, silent) {
		var action = btn.dataset.wdpdFix;
		var arg = btn.dataset.arg || '';
		var label = btn.innerHTML;
		btn.classList.add('is-busy');
		btn.disabled = true;

		var rounds = 0;
		function loop() {
			rounds++;
			return post('wdpd_fix', { fix: action, arg: arg }).then(function (r) {
				var task = btn.closest('.wdpd-task');
				if (task) {
					task.querySelector('[data-count]').textContent = fmt(r.remaining || 0);
				}
				if (r.remaining > 0 && r.done > 0 && rounds < 60) {
					return loop();
				}
				return r;
			});
		}

		return loop().then(function (r) {
			btn.classList.remove('is-busy');
			btn.classList.add('is-done');
			btn.textContent = action === 'autoload_restore' ? 'Geri alındı' : 'Tamamlandı';
			var task = btn.closest('.wdpd-task');
			if (task && !r.remaining) task.classList.add('is-clean');
			var row = btn.closest('tr');
			if (row) row.classList.add('is-done');
			var finding = btn.closest('.wdpd-finding');
			if (finding && !row) finding.classList.add('is-fixed');
			if (!silent) toast(r.message || 'İşlem tamamlandı.');
			return r;
		}, function (err) {
			btn.classList.remove('is-busy');
			btn.disabled = false;
			btn.innerHTML = label;
			toast(err.message, true);
			throw err;
		});
	}

	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-wdpd-fix]');
		if (!btn || btn.disabled) return;
		e.preventDefault();
		confirmBox(btn.dataset.confirm).then(function (ok) {
			if (ok) runFix(btn).catch(function () {});
		});
	});

	var cleanAll = $('[data-wdpd-clean-all]');
	if (cleanAll) {
		cleanAll.addEventListener('click', function () {
			var btns = $$('.wdpd-task[data-safe="1"] [data-wdpd-fix]').filter(function (b) { return !b.disabled; });
			if (!btns.length) return;
			confirmBox('Güvenli olarak işaretlenen ' + btns.length + ' temizlik görevi sırayla çalıştırılacak. Çöp kutusundaki içerikler dahil değildir. Devam edilsin mi?').then(function (ok) {
				if (!ok) return;
				cleanAll.classList.add('is-busy');
				var total = 0;
				var chain = Promise.resolve();
				btns.forEach(function (b) {
					chain = chain.then(function () {
						return runFix(b, true).then(function (r) { total += r.done || 0; }, function () {});
					});
				});
				chain.then(function () {
					cleanAll.classList.remove('is-busy');
					cleanAll.classList.add('is-done');
					cleanAll.disabled = true;
					cleanAll.querySelector('span').textContent = 'Temizlik tamamlandı';
					toast(fmt(total) + ' gereksiz kayıt temizlendi. Sonucu görmek için siteyi yeniden tarayın.');
				});
			});
		});
	}

	/* ---------- Bulgu filtresi ---------- */
	$$('.wdpd-filter button').forEach(function (b) {
		b.addEventListener('click', function () {
			$$('.wdpd-filter button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
			var f = b.dataset.filter;
			$$('.wdpd-finding').forEach(function (d) {
				var s = d.dataset.status;
				var show = f === 'all' || (f === 'problems' && (s === 'critical' || s === 'warning')) || f === s;
				d.hidden = !show;
			});
			$$('.wdpd-group').forEach(function (g) {
				g.hidden = !$$('.wdpd-finding', g).some(function (d) { return !d.hidden; });
			});
		});
	});

	/* ---------- Optimizasyon ayarları ---------- */
	var form = $('[data-wdpd-opts]');
	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = form.querySelector('[type="submit"]');
			var opts = {};
			$$('input, select', form).forEach(function (el) {
				if (!el.name) return;
				opts[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
			});
			btn.classList.add('is-busy');
			post('wdpd_save_opts', { opts: opts }).then(function (r) {
				btn.classList.remove('is-busy');
				toast(r.message);
			}, function (err) {
				btn.classList.remove('is-busy');
				toast(err.message, true);
			});
		});
	}

	/* ---------- Kopyalama ---------- */
	function copyText(text, btn) {
		function ok() {
			var span = btn.querySelector('span');
			var old = span ? span.textContent : '';
			if (span) span.textContent = 'Kopyalandı';
			setTimeout(function () { if (span) span.textContent = old; }, 1600);
		}
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(ok, function () { fallback(); });
		} else {
			fallback();
		}
		function fallback() {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); ok(); } catch (e) { toast('Kopyalanamadı; metni elle seçip kopyalayın.', true); }
			ta.remove();
		}
	}

	root.addEventListener('click', function (e) {
		var c = e.target.closest('[data-wdpd-copy]');
		if (c) {
			copyText(c.parentNode.querySelector('code').textContent, c);
			return;
		}
		var t = e.target.closest('[data-wdpd-copy-target]');
		if (t) {
			var el = document.getElementById(t.dataset.wdpdCopyTarget);
			if (el) copyText(el.value, t);
		}
	});
})();
