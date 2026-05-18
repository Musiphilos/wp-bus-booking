(() => {
	'use strict';

	const cfg = window.NVF_BB || {};
	const i18n = cfg.i18n || {};
	const pickups = cfg.pickups || {};
	const statusMap = cfg.statusLabels || {};

	const t = (key, fallback) => (i18n[key] != null ? i18n[key] : fallback);

	const RESEND_COOLDOWN = 30;

	async function apiFetch(path, { method = 'GET', body, nonce = true } = {}) {
		const headers = { 'Accept': 'application/json' };
		if (body !== undefined) headers['Content-Type'] = 'application/json';
		if (nonce) headers['X-WP-Nonce'] = cfg.restNonce;

		const res = await fetch(cfg.restBase + path, {
			method,
			credentials: 'same-origin',
			headers,
			body: body !== undefined ? JSON.stringify(body) : undefined,
		});
		let json = null;
		try { json = await res.json(); } catch (_) {}
		return { ok: res.ok, status: res.status, json };
	}

	function factory() {
		return {
			// ---- State ----
			email: cfg.profile && cfg.profile.email ? cfg.profile.email : '',
			profile: cfg.profile || null,
			signedIn: !!cfg.signedIn,
			busy: false,
			error: '',
			notice: cfg.flash && cfg.flash.type === 'success' ? cfg.flash.message : '',

			// Step-1 resend flow
			linkSent: false,
			resendIn: 0,
			_resendTimer: null,

			// Trip data + selection
			trips: { inbound: [], outbound: [], loaded: false, _byId: new Map() },
			selection: { inbound_trip_id: 0, inbound_pickup: '', outbound_trip_id: 0 },
			gdpr: false,
			step: 'choose',
			booking: null,
			cancelDirection: null,

			// Confirm dialog
			confirmOpen: false,
			confirmDirection: null,

			init() {
				if (cfg.flash && cfg.flash.type === 'error') this.error = cfg.flash.message;
				if (window.history && window.history.replaceState && cfg.flash && cfg.flash.type) {
					try { window.history.replaceState({}, document.title, window.location.pathname); } catch (_) {}
				}
				// Strip data-first-paint after first paint so the card-rise animation
				// doesn't fire again when the card unmounts/remounts (sign in/out).
				requestAnimationFrame(() => requestAnimationFrame(() => {
					const section = this.$el.closest('.nvf-bb');
					if (section) section.removeAttribute('data-first-paint');
				}));
				if (this.signedIn) this.refreshBookingAndTrips();
			},

			// ---- Auth ----
			async submitEmail() {
				if (!this.email || this.busy) return;
				this.busy = true; this.error = ''; this.notice = '';
				try {
					const res = await apiFetch('/verify-email', { method: 'POST', body: { email: this.email } });
					if (res.status === 429) {
						const seconds = (res.json && res.json.retry_after) || 60;
						this.error = t('rate_limited', 'Too many attempts. Please wait {minutes} minute(s) and try again.')
							.replace('{minutes}', String(Math.ceil(seconds / 60)));
						return;
					}
					if (!res.ok || (res.json && res.json.ok === false)) {
						this.error = (res.json && res.json.message) || t('verify_failed', 'Could not send the link. Please try again.');
						return;
					}
					this.notice = (res.json && res.json.message) || t('verify_sent', 'If that email is registered, we have sent you a sign-in link.');
					this.linkSent = true;
					this.startResendCooldown();
				} catch (err) {
					this.error = t('network_error', 'Network error. Please try again.');
					console.error('[nvf-bb] verify-email failed', err);
				} finally {
					this.busy = false;
				}
			},

			startResendCooldown() {
				this.resendIn = RESEND_COOLDOWN;
				if (this._resendTimer) clearInterval(this._resendTimer);
				this._resendTimer = setInterval(() => {
					this.resendIn -= 1;
					if (this.resendIn <= 0) {
						clearInterval(this._resendTimer);
						this._resendTimer = null;
						this.resendIn = 0;
					}
				}, 1000);
			},

			useDifferentEmail() {
				this.linkSent = false;
				this.notice = '';
				this.error = '';
				this.resendIn = 0;
				if (this._resendTimer) { clearInterval(this._resendTimer); this._resendTimer = null; }
				this.$nextTick(() => {
					const input = document.getElementById('nvf-bb-email');
					if (input) { input.focus(); input.select(); }
				});
			},

			async signOut() {
				try { await apiFetch('/logout', { method: 'POST' }); } catch (_) {}
				this.signedIn = false; this.profile = null; this.email = '';
				this.trips = { inbound: [], outbound: [], loaded: false, _byId: new Map() };
				this.booking = null; this.bookingResult = null;
				this.selection = { inbound_trip_id: 0, inbound_pickup: '', outbound_trip_id: 0 };
				this.step = 'choose';
				this.linkSent = false;
			},

			// ---- Booking flow ----
			refreshBookingAndTrips() {
				return Promise.all([this.loadTrips(), this.loadBooking()]);
			},

			async loadTrips() {
				try {
					const res = await apiFetch('/trips', { nonce: false });
					const trips = (res.json && res.json.trips) || [];
					const byId = new Map();
					trips.forEach(tr => byId.set(tr.id, tr));
					this.trips.inbound  = trips.filter(tr => tr.direction === 'OPO-IN');
					this.trips.outbound = trips.filter(tr => tr.direction === 'OPO-OUT');
					this.trips._byId = byId;
					this.trips.loaded = true;
				} catch (err) {
					console.error('[nvf-bb] /trips failed', err);
				}
			},

			async loadBooking() {
				try {
					const res = await apiFetch('/my-booking');
					this.booking = (res.json && res.json.booking) || null;
					if (this.booking && this.hasAnyActiveDirection(this.booking)) {
						this.step = 'done';
					}
				} catch (err) {
					console.error('[nvf-bb] /my-booking failed', err);
				}
			},

			hasAnyActiveDirection(b) {
				if (!b) return false;
				const active = ['confirmed', 'waitlist'];
				const s1 = (b.inbound  && b.inbound.status)  || 'none';
				const s2 = (b.outbound && b.outbound.status) || 'none';
				return active.includes(s1) || active.includes(s2);
			},

			tripById(id) {
				return this.trips._byId.get(id) || null;
			},

			toggleTrip(direction, tripId) {
				const key = direction === 'inbound' ? 'inbound_trip_id' : 'outbound_trip_id';
				if (this.selection[key] === tripId) {
					this.selection[key] = 0;
					if (direction === 'inbound') this.selection.inbound_pickup = '';
				} else {
					this.selection[key] = tripId;
				}
			},

			canSubmit() {
				if (this.busy) return false;
				if (!this.gdpr) return false;
				if (!this.selection.inbound_trip_id && !this.selection.outbound_trip_id) return false;
				if (this.selection.inbound_trip_id && !this.selection.inbound_pickup) return false;
				return true;
			},

			async submitBooking() {
				if (!this.canSubmit()) return;
				this.busy = true; this.error = ''; this.notice = '';
				try {
					const res = await apiFetch('/book', {
						method: 'POST',
						body: {
							inbound_trip_id:  this.selection.inbound_trip_id  || 0,
							inbound_pickup:   this.selection.inbound_pickup   || '',
							outbound_trip_id: this.selection.outbound_trip_id || 0,
							gdpr: true,
						},
					});
					if (!res.ok || (res.json && res.json.ok === false)) {
						this.error = (res.json && res.json.message) || t('book_failed', 'Could not save the booking.');
						return;
					}
					this.bookingResult = res.json.result;
					await this.loadBooking();
					this.step = res.json.partial ? 'partial' : 'done';
					this.focusStepHeading();
				} catch (err) {
					this.error = t('network_error', 'Network error. Please try again.');
					console.error('[nvf-bb] /book failed', err);
				} finally {
					this.busy = false;
				}
			},

			// ---- Cancel via styled <dialog> ----
			openCancelDialog(direction) {
				this.confirmDirection = direction;
				this.confirmOpen = true;
				this.$nextTick(() => {
					const dlg = document.getElementById('nvf-bb-confirm');
					if (dlg && typeof dlg.showModal === 'function' && !dlg.open) dlg.showModal();
					const btn = dlg && dlg.querySelector('[data-confirm-cancel]');
					if (btn) btn.focus();
				});
			},

			closeCancelDialog() {
				const dlg = document.getElementById('nvf-bb-confirm');
				if (dlg && dlg.open) dlg.close();
				this.confirmOpen = false;
				this.confirmDirection = null;
			},

			async confirmCancel() {
				const direction = this.confirmDirection;
				this.closeCancelDialog();
				if (!direction) return;
				this.busy = true; this.cancelDirection = direction; this.error = '';
				try {
					const res = await apiFetch('/my-booking?direction=' + encodeURIComponent(direction), { method: 'DELETE' });
					if (res.json && res.json.booking) this.booking = res.json.booking;
					if (!this.hasAnyActiveDirection(this.booking)) {
						this.step = 'choose';
						this.bookingResult = null;
						this.selection = { inbound_trip_id: 0, inbound_pickup: '', outbound_trip_id: 0 };
						this.gdpr = false;
					}
				} catch (err) {
					this.error = t('cancel_failed', 'Could not cancel. Please retry.');
					console.error('[nvf-bb] cancel failed', err);
				} finally {
					this.busy = false;
					this.cancelDirection = null;
				}
			},

			focusStepHeading() {
				this.$nextTick(() => {
					const card = document.querySelector('.nvf-bb__card');
					const h = card && card.querySelector('h2');
					if (h) {
						h.setAttribute('tabindex', '-1');
						h.focus({ preventScroll: false });
					}
				});
			},

			// ---- Formatters (i18n via cfg) ----
			fmtAvailability(tr) {
				if (!tr) return '';
				if (tr.status === 'cancelled') return t('avail_cancelled', 'Cancelled');
				if (tr.available > 0) {
					return t('avail_left', '{n} of {total} seats left')
						.replace('{n}', String(tr.available))
						.replace('{total}', String(tr.capacity));
				}
				return t('avail_full', 'Full — join the waitlist');
			},

			pickupLabel(code) { return pickups[code] || code || ''; },

			statusLabel(s) { return statusMap[s] || s; },
		};
	}

	document.addEventListener('alpine:init', () => {
		if (window.Alpine && typeof window.Alpine.data === 'function') {
			window.Alpine.data('nvfBooking', factory);
		}
	});

	window.nvfBooking = factory;
})();
