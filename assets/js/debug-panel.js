(function () {
	'use strict';

	var cfg = window.NVF_BB_DEBUG;
	if (!cfg || !cfg.restUrl) return;

	var consoleBuffer = [];
	var MAX_BUFFER = 200;
	['log', 'info', 'warn', 'error'].forEach(function (level) {
		var orig = console[level];
		console[level] = function () {
			try {
				consoleBuffer.push({
					ts: new Date().toISOString(),
					level: level,
					args: Array.prototype.slice.call(arguments).map(safeStringify),
				});
				if (consoleBuffer.length > MAX_BUFFER) consoleBuffer.shift();
			} catch (e) { /* ignore */ }
			return orig.apply(console, arguments);
		};
	});
	window.addEventListener('error', function (e) {
		consoleBuffer.push({ ts: new Date().toISOString(), level: 'error', args: [String(e.message), e.filename + ':' + e.lineno] });
	});
	window.addEventListener('unhandledrejection', function (e) {
		consoleBuffer.push({ ts: new Date().toISOString(), level: 'error', args: ['unhandledrejection', safeStringify(e.reason)] });
	});

	function safeStringify(value) {
		if (typeof value === 'string') return value;
		try { return JSON.stringify(value); } catch (e) { return String(value); }
	}

	var root = document.getElementById('nvf-debug-root');
	if (!root) {
		root = document.createElement('div');
		root.id = 'nvf-debug-root';
		document.body.appendChild(root);
	}

	var STORAGE_KEY = 'nvf_debug_collapsed';
	var startCollapsed = false;
	try { startCollapsed = window.localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) { /* private mode */ }

	var panel = document.createElement('div');
	panel.className = 'nvf-debug' + (startCollapsed ? ' nvf-debug--collapsed' : '');
	panel.innerHTML = [
		'<div class="nvf-debug__header" data-toggle role="button" tabindex="0" aria-label="Toggle debug panel">',
		'  <span class="nvf-debug__dot"></span>',
		'  <span class="nvf-debug__title">NVF debug</span>',
		'  <span class="nvf-debug__spacer"></span>',
		'  <button class="nvf-debug__btn" data-copy type="button" title="Copy snapshot + console buffer to clipboard">Copy all</button>',
		'  <button class="nvf-debug__btn" data-refresh type="button" title="Refresh now">↻</button>',
		'  <button class="nvf-debug__btn nvf-debug__chevron" data-toggle-btn type="button" aria-label="Collapse/expand">',
		'    <span class="nvf-debug__chevron-icon" aria-hidden="true">▾</span>',
		'  </button>',
		'</div>',
		'<div class="nvf-debug__body">',
		'  <div class="nvf-debug__section"><h4>Environment</h4><div class="nvf-debug__kv" data-env></div></div>',
		'  <div class="nvf-debug__section"><h4>Request</h4><div class="nvf-debug__kv" data-req></div></div>',
		'  <div class="nvf-debug__section"><h4>Session</h4><div class="nvf-debug__kv" data-sess></div></div>',
		'  <div class="nvf-debug__section"><h4>Recent log</h4><div class="nvf-debug__log" data-log></div></div>',
		'  <div class="nvf-debug__section"><h4>Browser console</h4><div class="nvf-debug__log" data-console></div></div>',
		'</div>',
	].join('');
	root.appendChild(panel);

	var lastSnapshot = null;

	function setCollapsed(collapsed) {
		panel.classList.toggle('nvf-debug--collapsed', !!collapsed);
		try { window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (e) {}
	}

	// Whole-header click toggles, but skip when the click was on a child button
	// so "Copy all" / refresh still work as normal buttons.
	panel.querySelector('[data-toggle]').addEventListener('click', function (e) {
		if (e.target.closest('[data-copy], [data-refresh]')) return;
		setCollapsed(!panel.classList.contains('nvf-debug--collapsed'));
	});
	// Keyboard activation on the header itself.
	panel.querySelector('[data-toggle]').addEventListener('keydown', function (e) {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			setCollapsed(!panel.classList.contains('nvf-debug--collapsed'));
		}
	});
	panel.querySelector('[data-refresh]').addEventListener('click', function (e) {
		e.stopPropagation();
		refresh();
	});
	panel.querySelector('[data-copy]').addEventListener('click', function (e) {
		e.stopPropagation();
		var payload = {
			generated_at: new Date().toISOString(),
			page_url: window.location.href,
			snapshot: lastSnapshot,
			console: consoleBuffer.slice(),
		};
		navigator.clipboard.writeText(JSON.stringify(payload, null, 2)).then(function () {
			flash(e.target, 'Copied');
		}, function () {
			flash(e.target, 'Failed');
		});
	});

	function flash(btn, label) {
		var prev = btn.textContent;
		btn.textContent = label;
		setTimeout(function () { btn.textContent = prev; }, 1200);
	}

	function kv(target, obj) {
		target.innerHTML = '';
		Object.keys(obj || {}).forEach(function (k) {
			var key = document.createElement('span'); key.textContent = k;
			var val = document.createElement('span'); val.textContent = formatVal(obj[k]);
			target.appendChild(key); target.appendChild(val);
		});
	}

	function formatVal(v) {
		if (v === null || v === undefined) return '—';
		if (typeof v === 'object') return JSON.stringify(v);
		return String(v);
	}

	function renderLog(target, lines) {
		target.innerHTML = '';
		(lines || []).slice().reverse().forEach(function (entry) {
			var div = document.createElement('div');
			div.className = 'nvf-debug__log-line nvf-debug__log-line--' + (entry.level || 'info');
			div.textContent = '[' + entry.ts + '] ' + entry.level + ' ' + entry.event + (entry.context ? ' ' + JSON.stringify(entry.context) : '');
			target.appendChild(div);
		});
	}

	function renderConsole(target) {
		target.innerHTML = '';
		consoleBuffer.slice().reverse().forEach(function (entry) {
			var div = document.createElement('div');
			div.className = 'nvf-debug__log-line nvf-debug__log-line--' + (entry.level === 'warn' ? 'warning' : entry.level);
			div.textContent = '[' + entry.ts + '] ' + entry.level + ' ' + entry.args.join(' ');
			target.appendChild(div);
		});
	}

	function refresh() {
		var url = cfg.restUrl + '?lines=80';
		if (cfg.debugKey) url += '&nvf_debug=' + encodeURIComponent(cfg.debugKey);
		fetch(url, { headers: { 'X-WP-Nonce': cfg.nonce || '' }, credentials: 'same-origin' })
			.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
			.then(function (res) {
				if (!res.ok) {
					panel.querySelector('[data-env]').innerHTML = '<span class="nvf-debug__error">' + (res.json && res.json.message ? res.json.message : 'error') + '</span>';
					return;
				}
				lastSnapshot = res.json;
				kv(panel.querySelector('[data-env]'), Object.assign({}, res.json.plugin, res.json.environment));
				kv(panel.querySelector('[data-req]'), res.json.request);
				kv(panel.querySelector('[data-sess]'), res.json.session);
				renderLog(panel.querySelector('[data-log]'), res.json.recent_log);
				renderConsole(panel.querySelector('[data-console]'));
			})
			.catch(function (err) {
				console.error('[nvf-debug] fetch failed', err);
			});
	}

	refresh();
	setInterval(refresh, cfg.pollMs || 5000);
})();
