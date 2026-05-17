<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Rest;

use NVF\BusBooking\Auth\ElementorLookup;
use NVF\BusBooking\Auth\SessionCookie;
use NVF\BusBooking\Support\Settings;
use NVF\BusBooking\Support\StringRenderer;

/**
 * Front-end shortcode + asset loader.
 *
 * Today this renders Step 1 of the booking flow (email entry + signed-in
 * acknowledgement). Steps 2 (trip selection) and 3 (confirmation) land in M3.
 */
final class PublicAssets {

	public const SHORTCODE = 'nvf_booking_page';

	public static function register(): void {
		add_shortcode( self::SHORTCODE, [ self::class, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue(): void {
		wp_register_style( 'nvf-bb', NVF_BB_URL . 'assets/css/booking.css', [], NVF_BB_VERSION );
		wp_register_style( 'nvf-bb-debug', NVF_BB_URL . 'assets/css/debug-panel.css', [], NVF_BB_VERSION );
		wp_register_script( 'nvf-bb', NVF_BB_URL . 'assets/js/booking.js', [], NVF_BB_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		// Alpine must execute AFTER booking.js so the component factory is registered before alpine:init fires.
		wp_register_script( 'nvf-bb-alpine', 'https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js', [ 'nvf-bb' ], '3.14.1', [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_register_script( 'nvf-bb-debug', NVF_BB_URL . 'assets/js/debug-panel.js', [], NVF_BB_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}

	public static function bookingPageUrl(): string {
		$pages = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			's'              => '[' . self::SHORTCODE . ']',
		] );
		if ( $pages && isset( $pages[0] ) ) {
			return get_permalink( $pages[0] );
		}
		return '';
	}

	public static function render(): string {
		wp_enqueue_style( 'nvf-bb' );
		wp_enqueue_script( 'nvf-bb-alpine' );
		wp_enqueue_script( 'nvf-bb' );

		$session  = SessionCookie::read();
		$profile  = $session ? ElementorLookup::findByEmail( $session['email'] ) : null;

		$config = [
			'restBase'   => esc_url_raw( rest_url( 'nvf/v1' ) ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'pageUrl'    => self::bookingPageUrl() ?: home_url( $_SERVER['REQUEST_URI'] ?? '/' ),
			'signedIn'   => (bool) $session,
			'profile'    => $profile,
			'flash'      => self::readFlash(),
		];
		wp_add_inline_script(
			'nvf-bb',
			'window.NVF_BB = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		$debugEnabled = self::shouldShowDebug();
		if ( $debugEnabled ) {
			wp_enqueue_style( 'nvf-bb-debug' );
			wp_enqueue_script( 'nvf-bb-debug' );
			wp_add_inline_script(
				'nvf-bb-debug',
				'window.NVF_BB_DEBUG = ' . wp_json_encode( [
					'restUrl'  => esc_url_raw( rest_url( 'nvf/v1/debug' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'debugKey' => wp_create_nonce( \NVF\BusBooking\Rest\DebugController::NONCE_NAME ),
					'pollMs'   => 5000,
				] ) . ';',
				'before'
			);
		}

		// Shared token context for all booking-page strings. Built once so the
		// renderer doesn't re-read settings on every call.
		$textCtx = self::textContext();

		ob_start();
		?>
		<section class="nvf-bb" data-nvf-version="<?php echo esc_attr( NVF_BB_VERSION ); ?>">
			<header class="nvf-bb__header">
				<p class="nvf-bb__eyebrow"><?php echo esc_html( StringRenderer::render( 'booking.eyebrow' ) ); ?></p>
				<h1 class="nvf-bb__title"><?php echo esc_html( StringRenderer::render( 'booking.h1' ) ); ?></h1>
				<div class="nvf-bb__tripstrip" aria-label="<?php esc_attr_e( 'Trip overview', 'nvf-bus-booking' ); ?>">
					<span><?php echo esc_html( StringRenderer::render( 'booking.trip_strip.dates' ) ); ?></span>
					<span class="nvf-bb__sep" aria-hidden="true">·</span>
					<span><?php echo esc_html( StringRenderer::render( 'booking.trip_strip.origin' ) ); ?></span>
					<span class="nvf-bb__arrow" aria-hidden="true">⇄</span>
					<span><?php echo esc_html( StringRenderer::render( 'booking.trip_strip.destination' ) ); ?></span>
				</div>
				<p class="nvf-bb__lede">
					<?php echo esc_html( StringRenderer::render( 'booking.lede' ) ); ?>
				</p>
			</header>

			<div id="nvf-bb-app" x-data="nvfBooking">
				<!-- Signed-in state: trip selection / confirmation / done -->
				<template x-if="signedIn">
					<div class="nvf-bb__card">
						<div class="nvf-bb__badge">
							<span class="nvf-bb__dot"></span>
							<span><?php esc_html_e( 'Signed in', 'nvf-bus-booking' ); ?></span>
						</div>

						<p class="nvf-bb__greeting" x-text="profile && profile.name ? ('Welcome, ' + profile.name) : 'Welcome'"></p>
						<p class="nvf-bb__caption">
							<span x-text="email"></span>
							<template x-if="profile && profile.phone"><span class="nvf-bb__sep" aria-hidden="true">·</span></template>
							<template x-if="profile && profile.phone"><span x-text="profile.phone"></span></template>
						</p>

						<!-- ===== Step 2/3: Choose & confirm ===== -->
						<template x-if="step === 'choose'">
							<div>
								<h2><?php esc_html_e( 'Step 2 · Choose your trip', 'nvf-bus-booking' ); ?></h2>
								<p class="nvf-bb__muted">
									<?php esc_html_e( "Pick one inbound shuttle (Sep 24) and / or one outbound shuttle (Sep 28). You can book just one direction if you're arriving or leaving on your own.", 'nvf-bus-booking' ); ?>
								</p>

								<template x-if="!trips.loaded">
									<p class="nvf-bb__muted"><?php esc_html_e( 'Loading trips…', 'nvf-bus-booking' ); ?></p>
								</template>

								<template x-if="trips.loaded">
									<div class="nvf-bb__trips">
										<!-- Inbound -->
										<fieldset class="nvf-bb__group">
											<legend><?php esc_html_e( 'Inbound · Sep 24', 'nvf-bus-booking' ); ?></legend>
											<template x-for="t in trips.inbound" :key="t.id">
												<label class="nvf-bb__trip" :class="{ 'is-selected': selection.inbound_trip_id === t.id, 'is-full': t.available === 0 }">
													<input type="radio" name="inbound"
													       :value="t.id"
													       :checked="selection.inbound_trip_id === t.id"
													       @click="toggleTrip('inbound', t.id)" />
													<div class="nvf-bb__trip-body">
														<div class="nvf-bb__trip-row">
															<span class="nvf-bb__trip-code" x-text="t.code"></span>
															<span class="nvf-bb__trip-time" x-text="t.departure"></span>
														</div>
														<div class="nvf-bb__trip-avail" x-text="fmtAvailability(t)"></div>
													</div>
												</label>
											</template>

											<template x-if="selection.inbound_trip_id">
												<div class="nvf-bb__pickup">
													<p class="nvf-bb__label"><?php esc_html_e( 'Where are we picking you up?', 'nvf-bus-booking' ); ?></p>
													<label class="nvf-bb__radio">
														<input type="radio" name="pickup" value="airport" x-model="selection.inbound_pickup" />
														<span><?php esc_html_e( 'Porto Airport (Vodafone store)', 'nvf-bus-booking' ); ?></span>
													</label>
													<label class="nvf-bb__radio">
														<input type="radio" name="pickup" value="casa_da_musica" x-model="selection.inbound_pickup" />
														<span><?php esc_html_e( 'Terminal Alsa/Autna — Casa da Música', 'nvf-bus-booking' ); ?></span>
													</label>
												</div>
											</template>
										</fieldset>

										<!-- Outbound -->
										<fieldset class="nvf-bb__group">
											<legend><?php esc_html_e( 'Outbound · Sep 28', 'nvf-bus-booking' ); ?></legend>
											<template x-for="t in trips.outbound" :key="t.id">
												<label class="nvf-bb__trip" :class="{ 'is-selected': selection.outbound_trip_id === t.id, 'is-full': t.available === 0 }">
													<input type="radio" name="outbound"
													       :value="t.id"
													       :checked="selection.outbound_trip_id === t.id"
													       @click="toggleTrip('outbound', t.id)" />
													<div class="nvf-bb__trip-body">
														<div class="nvf-bb__trip-row">
															<span class="nvf-bb__trip-code" x-text="t.code"></span>
															<span class="nvf-bb__trip-time" x-text="t.departure"></span>
														</div>
														<div class="nvf-bb__trip-avail" x-text="fmtAvailability(t)"></div>
													</div>
												</label>
											</template>
										</fieldset>
									</div>
								</template>

								<label class="nvf-bb__gdpr">
									<input type="checkbox" x-model="gdpr" />
									<span><?php echo esc_html( StringRenderer::render( 'booking.gdpr_label' ) ); ?></span>
								</label>

								<template x-if="error"><p class="nvf-bb__error" x-text="error"></p></template>

								<div class="nvf-bb__actions">
									<button type="button" class="nvf-bb__btn" :disabled="!canSubmit()" @click="submitBooking">
										<span x-show="!busy"><?php esc_html_e( 'Confirm booking', 'nvf-bus-booking' ); ?></span>
										<span x-show="busy"><?php esc_html_e( 'Saving…', 'nvf-bus-booking' ); ?></span>
									</button>
									<button type="button" class="nvf-bb__btn nvf-bb__btn--ghost" @click="signOut">
										<?php esc_html_e( 'Use a different email', 'nvf-bus-booking' ); ?>
									</button>
								</div>
							</div>
						</template>

						<!-- ===== Partial availability — informational, booking already committed ===== -->
						<template x-if="step === 'partial'">
							<div>
								<h2><?php esc_html_e( 'Booking saved — partial availability', 'nvf-bus-booking' ); ?></h2>
								<p class="nvf-bb__muted">
									<?php esc_html_e( 'One of the trips you picked is full. You are on the waiting list for that one and confirmed for the other. We will email you if a seat opens.', 'nvf-bus-booking' ); ?>
								</p>
								<div class="nvf-bb__summary">
									<template x-if="booking && booking.inbound && booking.inbound.status !== 'none'">
										<div class="nvf-bb__summary-row">
											<span><?php esc_html_e( 'Inbound', 'nvf-bus-booking' ); ?></span>
											<span x-text="statusLabel(booking.inbound.status)"></span>
										</div>
									</template>
									<template x-if="booking && booking.outbound && booking.outbound.status !== 'none'">
										<div class="nvf-bb__summary-row">
											<span><?php esc_html_e( 'Outbound', 'nvf-bus-booking' ); ?></span>
											<span x-text="statusLabel(booking.outbound.status)"></span>
										</div>
									</template>
								</div>
								<div class="nvf-bb__actions">
									<button type="button" class="nvf-bb__btn" @click="step = 'done'"><?php esc_html_e( 'See booking', 'nvf-bus-booking' ); ?></button>
								</div>
							</div>
						</template>

						<!-- ===== Done — current booking summary ===== -->
						<template x-if="step === 'done' && booking">
							<div>
								<h2><?php esc_html_e( 'Your booking', 'nvf-bus-booking' ); ?></h2>

								<template x-if="booking.inbound && booking.inbound.status !== 'none'">
									<div class="nvf-bb__leg">
										<div class="nvf-bb__leg-head">
											<span class="nvf-bb__leg-dir"><?php esc_html_e( 'Inbound', 'nvf-bus-booking' ); ?></span>
											<span class="nvf-bb__leg-status" :class="'is-' + booking.inbound.status" x-text="statusLabel(booking.inbound.status)"></span>
										</div>
										<div class="nvf-bb__leg-body">
											<template x-if="tripById(booking.inbound.trip_id)">
												<div>
													<span class="nvf-bb__leg-code" x-text="tripById(booking.inbound.trip_id).code"></span>
													·
													<span x-text="tripById(booking.inbound.trip_id).departure"></span>
												</div>
											</template>
											<template x-if="booking.inbound.pickup">
												<div class="nvf-bb__leg-pickup">
													<?php esc_html_e( 'Pickup', 'nvf-bus-booking' ); ?>:
													<span x-text="pickupLabel(booking.inbound.pickup)"></span>
												</div>
											</template>
										</div>
										<button type="button" class="nvf-bb__btn nvf-bb__btn--ghost nvf-bb__btn--small" :disabled="busy" @click="cancelDirectionCall('inbound')">
											<?php esc_html_e( 'Cancel inbound', 'nvf-bus-booking' ); ?>
										</button>
									</div>
								</template>

								<template x-if="booking.outbound && booking.outbound.status !== 'none'">
									<div class="nvf-bb__leg">
										<div class="nvf-bb__leg-head">
											<span class="nvf-bb__leg-dir"><?php esc_html_e( 'Outbound', 'nvf-bus-booking' ); ?></span>
											<span class="nvf-bb__leg-status" :class="'is-' + booking.outbound.status" x-text="statusLabel(booking.outbound.status)"></span>
										</div>
										<div class="nvf-bb__leg-body">
											<template x-if="tripById(booking.outbound.trip_id)">
												<div>
													<span class="nvf-bb__leg-code" x-text="tripById(booking.outbound.trip_id).code"></span>
													·
													<span x-text="tripById(booking.outbound.trip_id).departure"></span>
												</div>
											</template>
										</div>
										<button type="button" class="nvf-bb__btn nvf-bb__btn--ghost nvf-bb__btn--small" :disabled="busy" @click="cancelDirectionCall('outbound')">
											<?php esc_html_e( 'Cancel outbound', 'nvf-bus-booking' ); ?>
										</button>
									</div>
								</template>

								<template x-if="error"><p class="nvf-bb__error" x-text="error"></p></template>

								<p class="nvf-bb__fine">
									<?php echo self::linkifyContact( StringRenderer::render( 'booking.help_line', $textCtx ), $textCtx['contact_email'] ); ?>
								</p>

								<div class="nvf-bb__actions">
									<button type="button" class="nvf-bb__btn nvf-bb__btn--ghost" @click="signOut">
										<?php esc_html_e( 'Sign out', 'nvf-bus-booking' ); ?>
									</button>
								</div>
							</div>
						</template>

						<?php self::renderIncludesList(); ?>
					</div>
				</template>

				<!-- Step 1: email entry -->
				<template x-if="!signedIn">
					<div class="nvf-bb__card">
						<h2><?php echo esc_html( StringRenderer::render( 'booking.step1.heading' ) ); ?></h2>
						<p class="nvf-bb__muted">
							<?php echo esc_html( StringRenderer::render( 'booking.step1.intro', $textCtx ) ); ?>
						</p>

						<form @submit.prevent="submitEmail" novalidate>
							<label class="nvf-bb__label" for="nvf-bb-email"><?php esc_html_e( 'Email address', 'nvf-bus-booking' ); ?></label>
							<input id="nvf-bb-email" class="nvf-bb__input" type="email" autocomplete="email"
							       required x-model.trim="email" :disabled="busy" placeholder="you@example.com" />

							<template x-if="error">
								<p class="nvf-bb__error" x-text="error"></p>
							</template>
							<template x-if="notice">
								<p class="nvf-bb__notice" x-text="notice"></p>
							</template>

							<div class="nvf-bb__actions">
								<button type="submit" class="nvf-bb__btn" :disabled="busy || !email">
									<span x-show="!busy"><?php esc_html_e( 'Send me the sign-in link', 'nvf-bus-booking' ); ?></span>
									<span x-show="busy"><?php esc_html_e( 'Sending…', 'nvf-bus-booking' ); ?></span>
								</button>
							</div>
						</form>

						<p class="nvf-bb__fine">
							<?php echo self::linkifyContact( StringRenderer::render( 'booking.step1.footnote', $textCtx ), $textCtx['contact_email'] ); ?>
						</p>

						<?php self::renderIncludesList(); ?>
					</div>
				</template>
			</div>

			<?php
			$privacyUrl = (string) \NVF\BusBooking\Support\Settings::get( 'nvf_privacy_policy_url', '' );
			if ( $privacyUrl !== '' ) :
			?>
				<p class="nvf-bb__privacy">
					<?php
					printf(
						/* translators: %s: privacy policy link */
						esc_html__( 'Your booking details are processed per our %s.', 'nvf-bus-booking' ),
						'<a href="' . esc_url( $privacyUrl ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy notice', 'nvf-bus-booking' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $debugEnabled ) : ?>
				<div id="nvf-debug-root"></div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Surface ?nvf_signed_in=1 / ?nvf_error=… into the front-end so the
	 * Alpine app can show a confirmation or error toast on the consume redirect.
	 */
	private static function readFlash(): array {
		if ( isset( $_GET['nvf_signed_in'] ) ) {
			return [ 'type' => 'success', 'message' => __( 'You are signed in.', 'nvf-bus-booking' ) ];
		}
		if ( isset( $_GET['nvf_claimed'] ) ) {
			return [ 'type' => 'success', 'message' => __( 'Seat claimed — you are confirmed. Confirmation email on its way.', 'nvf-bus-booking' ) ];
		}
		if ( isset( $_GET['nvf_error'] ) ) {
			$code = sanitize_key( wp_unslash( $_GET['nvf_error'] ) );
			$map  = [
				'invalid_link' => __( 'That link is invalid or has expired. Please request a new one.', 'nvf-bus-booking' ),
				'spot_taken'   => __( 'Someone else claimed that seat first. You remain on the waiting list in your current position — we will email you when the next seat opens.', 'nvf-bus-booking' ),
			];
			return [ 'type' => 'error', 'message' => $map[ $code ] ?? __( 'Something went wrong. Please try again.', 'nvf-bus-booking' ) ];
		}
		return [ 'type' => null, 'message' => '' ];
	}

	private static function shouldShowDebug(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$nonce = isset( $_GET['nvf_debug'] ) ? (string) $_GET['nvf_debug'] : '';
		return $nonce !== '' && wp_verify_nonce( $nonce, \NVF\BusBooking\Rest\DebugController::NONCE_NAME ) !== false;
	}

	/**
	 * Shared token context for every booking-page string. Currently surfaces
	 * the contact email + magic-link TTL — anything declared as a token on
	 * a booking-page registry entry should land here.
	 *
	 * @return array<string,scalar|null>
	 */
	private static function textContext(): array {
		return [
			'contact_email' => Settings::contactEmail(),
			'ttl_hours'     => (int) Settings::get( 'nvf_magic_link_expiry_hours', 24 ),
		];
	}

	/**
	 * Render the "What's included" bleed footer. An empty registry override
	 * for any of the four bullets hides that row, so admins can collapse the
	 * grid from 4 → 3 → 2 → none by emptying the corresponding fields.
	 */
	private static function renderIncludesList(): void {
		$items = [];
		foreach ( [ 'booking.includes.1', 'booking.includes.2', 'booking.includes.3', 'booking.includes.4' ] as $id ) {
			$v = StringRenderer::render( $id );
			if ( $v !== '' ) {
				$items[] = $v;
			}
		}
		if ( ! $items ) {
			return;
		}
		?>
		<ul class="nvf-bb__includes" aria-label="<?php esc_attr_e( "What's included", 'nvf-bus-booking' ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<li><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Find a plain email address inside an already-rendered string and wrap it
	 * in a mailto: link. Keeps the surrounding text safe — only the email
	 * substring becomes an anchor.
	 *
	 * Used to preserve clickable contact links after the surrounding copy
	 * became admin-editable. The string itself stays plain text (no HTML
	 * markup in registry defaults) so admins can edit freely.
	 */
	private static function linkifyContact( string $rendered, string $contactEmail ): string {
		if ( $contactEmail === '' || strpos( $rendered, $contactEmail ) === false ) {
			return esc_html( $rendered );
		}
		$parts = explode( $contactEmail, $rendered, 2 );
		return esc_html( $parts[0] )
			. '<a href="mailto:' . esc_attr( $contactEmail ) . '">' . esc_html( $contactEmail ) . '</a>'
			. esc_html( $parts[1] ?? '' );
	}
}
