(function () {
	'use strict';

	function factory() {
		var cfg = window.NVF_BB || {};
		return {
			// ---- State ----
			email: cfg.profile && cfg.profile.email ? cfg.profile.email : '',
			profile: cfg.profile || null,
			signedIn: !!cfg.signedIn,
			busy: false,
			error: '',
			notice: cfg.flash && cfg.flash.type === 'success' ? cfg.flash.message : '',

			// Trip data + selection
			trips: { inbound: [], outbound: [], loaded: false },
			selection: { inbound_trip_id: 0, inbound_pickup: '', outbound_trip_id: 0 },
			gdpr: false,
			step: 'choose',          // 'choose' | 'confirm' | 'partial' | 'done'
			booking: null,            // current booking snapshot (server)
			bookingResult: null,      // result of last book POST
			cancelDirection: null,    // which direction is being cancelled (for UI affordance)

			init: function () {
				if (cfg.flash && cfg.flash.type === 'error') this.error = cfg.flash.message;
				if (window.history && window.history.replaceState && (cfg.flash && cfg.flash.type)) {
					try { window.history.replaceState({}, document.title, window.location.pathname); } catch (e) {}
				}
				if (this.signedIn) this.refreshBookingAndTrips();
			},

			// ---- Auth ----
			submitEmail: function () {
				var self = this;
				if (!self.email || self.busy) return;
				self.busy = true; self.error = ''; self.notice = '';
				fetch(cfg.restBase + '/verify-email', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce },
					body: JSON.stringify({ email: self.email }),
				})
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, json: j }; }); })
					.then(function (res) {
						self.busy = false;
						if (res.status === 429) {
							var seconds = (res.json && res.json.retry_after) || 60;
							self.error = 'Too many attempts. Please wait ' + Math.ceil(seconds / 60) + ' minute(s) and try again.';
							return;
						}
						if (!res.ok || (res.json && res.json.ok === false)) {
							self.error = (res.json && res.json.message) || 'Could not send the link. Please try again.';
							return;
						}
						self.notice = (res.json && res.json.message) || 'If that email is registered, we have sent you a sign-in link.';
					})
					.catch(function (err) {
						self.busy = false;
						self.error = 'Network error. Please try again.';
						console.error('[nvf-bb] verify-email failed', err);
					});
			},

			signOut: function () {
				var self = this;
				fetch(cfg.restBase + '/logout', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.restNonce },
				}).finally(function () {
					self.signedIn = false; self.profile = null; self.email = '';
					self.trips = { inbound: [], outbound: [], loaded: false };
					self.booking = null; self.bookingResult = null;
					self.selection = { inbound_trip_id: 0, inbound_pickup: '', outbound_trip_id: 0 };
					self.step = 'choose';
				});
			},

			// ---- Booking flow ----
			refreshBookingAndTrips: function () {
				return Promise.all([this.loadTrips(), this.loadBooking()]);
			},

			loadTrips: function () {
				var self = this;
				return fetch(cfg.restBase + '/trips', { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						var trips = j.trips || [];
						self.trips.inbound  = trips.filter(function (t) { return t.direction === 'OPO-IN'; });
						self.trips.outbound = trips.filter(function (t) { return t.direction === 'OPO-OUT'; });
						self.trips.loaded = true;
					})
					.catch(function (err) { console.error('[nvf-bb] /trips failed', err); });
			},

			loadBooking: function () {
				var self = this;
				return fetch(cfg.restBase + '/my-booking', {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.restNonce },
				})
					.then(function (r) { return r.json(); })
					.then(function (j) {
						self.booking = j.booking || null;
						if (self.booking && self.hasAnyActiveDirection(self.booking)) {
							self.step = 'done';
						}
					})
					.catch(function (err) { console.error('[nvf-bb] /my-booking failed', err); });
			},

			hasAnyActiveDirection: function (b) {
				if (!b) return false;
				var s1 = (b.inbound  && b.inbound.status)  || 'none';
				var s2 = (b.outbound && b.outbound.status) || 'none';
				return ['confirmed','waitlist'].indexOf(s1) >= 0 || ['confirmed','waitlist'].indexOf(s2) >= 0;
			},

			tripById: function (id) {
				var all = this.trips.inbound.concat(this.trips.outbound);
				for (var i = 0; i < all.length; i++) if (all[i].id === id) return all[i];
				return null;
			},

			toggleTrip: function (direction, tripId) {
				var key = direction === 'inbound' ? 'inbound_trip_id' : 'outbound_trip_id';
				if (this.selection[key] === tripId) {
					this.selection[key] = 0;
					if (direction === 'inbound') this.selection.inbound_pickup = '';
				} else {
					this.selection[key] = tripId;
				}
			},

			canSubmit: function () {
				if (this.busy) return false;
				if (!this.gdpr) return false;
				if (!this.selection.inbound_trip_id && !this.selection.outbound_trip_id) return false;
				if (this.selection.inbound_trip_id && !this.selection.inbound_pickup) return false;
				return true;
			},

			submitBooking: function () {
				var self = this;
				if (!self.canSubmit()) return;
				self.busy = true; self.error = ''; self.notice = '';
				fetch(cfg.restBase + '/book', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce },
					body: JSON.stringify({
						inbound_trip_id:  self.selection.inbound_trip_id  || 0,
						inbound_pickup:   self.selection.inbound_pickup   || '',
						outbound_trip_id: self.selection.outbound_trip_id || 0,
						gdpr:             true,
					}),
				})
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, json: j }; }); })
					.then(function (res) {
						self.busy = false;
						if (!res.ok || (res.json && res.json.ok === false)) {
							self.error = (res.json && res.json.message) || 'Could not save the booking.';
							return;
						}
						self.bookingResult = res.json.result;
						return self.loadBooking().then(function () {
							self.step = res.json.partial ? 'partial' : 'done';
						});
					})
					.catch(function (err) {
						self.busy = false;
						self.error = 'Network error. Please try again.';
						console.error('[nvf-bb] /book failed', err);
					});
			},

			cancelDirectionCall: function (direction) {
				var self = this;
				if (!confirm('Cancel ' + direction + '?')) return;
				self.busy = true; self.cancelDirection = direction; self.error = '';
				fetch(cfg.restBase + '/my-booking?direction=' + encodeURIComponent(direction), {
					method: 'DELETE',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': cfg.restNonce },
				})
					.then(function (r) { return r.json(); })
					.then(function (j) {
						self.busy = false; self.cancelDirection = null;
						if (j && j.booking) self.booking = j.booking;
						if (!self.hasAnyActiveDirection(self.booking)) {
							// Both sides cancelled — let them book again.
							self.step = 'choose';
							self.bookingResult = null;
						}
					})
					.catch(function (err) {
						self.busy = false; self.cancelDirection = null;
						self.error = 'Could not cancel. Please retry.';
						console.error('[nvf-bb] cancel failed', err);
					});
			},

			startOver: function () {
				this.bookingResult = null;
				this.step = 'choose';
				this.selection = { inbound_trip_id: 0, inbound_pickup: '', outbound_trip_id: 0 };
				this.gdpr = false;
			},

			// ---- Formatters used by the template ----
			fmtAvailability: function (t) {
				if (!t) return '';
				if (t.status === 'cancelled') return 'Cancelled';
				return t.available > 0 ? (t.available + ' of ' + t.capacity + ' seats left') : 'Full — join the waitlist';
			},

			pickupLabel: function (code) {
				if (code === 'airport')        return 'Porto Airport (Vodafone store)';
				if (code === 'casa_da_musica') return 'Terminal Alsa/Autna — Casa da Música';
				return code || '';
			},

			statusLabel: function (s) {
				return ({
					confirmed: 'Confirmed',
					waitlist:  'On the waiting list',
					cancelled: 'Cancelled',
					none:      'Not booked',
				})[s] || s;
			},
		};
	}

	document.addEventListener('alpine:init', function () {
		if (window.Alpine && typeof window.Alpine.data === 'function') {
			window.Alpine.data('nvfBooking', factory);
		}
	});

	window.nvfBooking = factory;
})();
