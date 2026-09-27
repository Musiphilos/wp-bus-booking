<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Admin;

use NVF\BusBooking\Domain\PostTypes;
use WP_Query;

/**
 * "Leg coverage" filter on the `nvf_booking` list table.
 *
 * Answers the operational question the columns hint at but cannot be sorted on:
 * who is riding one direction only? The common case is a passenger booked on a
 * return shuttle with no inbound leg — they are arriving under their own steam,
 * and nobody should be holding an outbound seat for them.
 *
 * A leg counts as **held** when its status is `confirmed` or `waitlist`. Every
 * other state — `none`, `cancelled`, empty, or no meta row at all — is not held,
 * so someone who booked an inbound leg and later cancelled it shows up under
 * "Return only" alongside someone who never booked one. The Inbound column still
 * shows which of the two they are.
 *
 * Scope note: this filters on booking meta alone. Unlike {@see \NVF\BusBooking\Booking\LedgerReconciler},
 * it does not verify that the referenced trip still exists or that the trip's
 * direction matches the leg — a list query cannot join that cheaply. The filter
 * therefore agrees with what {@see BookingColumns} paints on screen, which may
 * drift from seat-ledger truth if trip meta is corrupt.
 */
final class BookingLegFilter {

	/** Query var carrying the selected mode. Bookmarkable; no nonce (read-only). */
	public const QUERY_VAR = 'nvf_leg_coverage';

	public const MODE_ALL          = 'all';
	public const MODE_BOTH         = 'both';
	public const MODE_RETURN_ONLY  = 'return-only';
	public const MODE_INBOUND_ONLY = 'inbound-only';
	/** Deliberately not `none` — that slug is already a *leg status* in this domain. */
	public const MODE_NO_LEGS      = 'no-legs';

	/** Statuses that mean the passenger actually holds a seat on that leg. */
	private const HELD_STATUSES = [ 'confirmed', 'waitlist' ];

	private const INBOUND_KEY  = 'inbound_status';
	private const OUTBOUND_KEY = 'outbound_status';

	public static function register(): void {
		add_action( 'restrict_manage_posts', [ self::class, 'renderDropdown' ] );
		add_action( 'pre_get_posts', [ self::class, 'applyFilter' ] );
	}

	/**
	 * Every mode the dropdown offers, in menu order. Pure — the labels live in
	 * {@see self::modes()} so this list stays usable without a WordPress runtime.
	 *
	 * @return array<int,string>
	 */
	public static function modeKeys(): array {
		return [
			self::MODE_ALL,
			self::MODE_BOTH,
			self::MODE_RETURN_ONLY,
			self::MODE_INBOUND_ONLY,
			self::MODE_NO_LEGS,
		];
	}

	/**
	 * Dropdown options, in menu order. Driven off {@see self::modeKeys()} so the
	 * two cannot drift, and total — an unlabelled mode falls back to its slug
	 * rather than emitting an undefined-key warning into the page.
	 *
	 * @return array<string,string> mode => translated label
	 */
	public static function modes(): array {
		$modes = [];
		foreach ( self::modeKeys() as $mode ) {
			$modes[ $mode ] = self::label( $mode );
		}

		return $modes;
	}

	private static function label( string $mode ): string {
		return match ( $mode ) {
			self::MODE_ALL          => __( 'Leg coverage: all', 'nvf-bus-booking' ),
			self::MODE_BOTH         => __( 'Both legs', 'nvf-bus-booking' ),
			self::MODE_RETURN_ONLY  => __( 'Return only', 'nvf-bus-booking' ),
			self::MODE_INBOUND_ONLY => __( 'Inbound only', 'nvf-bus-booking' ),
			self::MODE_NO_LEGS      => __( 'No legs', 'nvf-bus-booking' ),
			default                 => $mode,
		};
	}

	/**
	 * Build the `meta_query` for a mode. Pure — no WordPress calls, no I/O — so
	 * the whole rule set is unit-testable.
	 *
	 * An unknown or "all" mode yields an empty array, meaning "do not filter".
	 *
	 * @return array<mixed> WP_Query `meta_query` fragment, or [] for no filter.
	 */
	public static function metaQueryFor( string $mode ): array {
		switch ( $mode ) {
			case self::MODE_BOTH:
				return [
					'relation' => 'AND',
					self::held( self::INBOUND_KEY ),
					self::held( self::OUTBOUND_KEY ),
				];

			case self::MODE_RETURN_ONLY:
				return [
					'relation' => 'AND',
					self::held( self::OUTBOUND_KEY ),
					self::notHeld( self::INBOUND_KEY ),
				];

			case self::MODE_INBOUND_ONLY:
				return [
					'relation' => 'AND',
					self::held( self::INBOUND_KEY ),
					self::notHeld( self::OUTBOUND_KEY ),
				];

			case self::MODE_NO_LEGS:
				return [
					'relation' => 'AND',
					self::notHeld( self::INBOUND_KEY ),
					self::notHeld( self::OUTBOUND_KEY ),
				];

			default:
				return [];
		}
	}

	/** The mode currently selected in the URL, or MODE_ALL. */
	public static function currentMode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, same as core's date/category dropdowns.
		$raw = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		return in_array( $raw, self::modeKeys(), true ) ? $raw : self::MODE_ALL;
	}

	public static function renderDropdown( string $postType ): void {
		if ( $postType !== PostTypes::BOOKING ) {
			return;
		}

		$current = self::currentMode();
		?>
		<label class="screen-reader-text" for="<?php echo esc_attr( self::QUERY_VAR ); ?>">
			<?php esc_html_e( 'Filter by leg coverage', 'nvf-bus-booking' ); ?>
		</label>
		<select name="<?php echo esc_attr( self::QUERY_VAR ); ?>" id="<?php echo esc_attr( self::QUERY_VAR ); ?>">
			<?php foreach ( self::modes() as $mode => $label ) : ?>
				<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $mode, $current ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public static function applyFilter( WP_Query $query ): void {
		global $pagenow;

		// Narrow to the bookings list table specifically. The post_type check
		// alone would already be correct today, but this hook fires for every
		// admin main query — pinning the screen keeps a future one from
		// inheriting the filter by accident. A non-string post_type (some
		// screens pass an array) fails the strict compare and bails, which is
		// the right default: no filter rather than a wrong one.
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== PostTypes::BOOKING ) {
			return;
		}

		$filter = self::metaQueryFor( self::currentMode() );
		if ( $filter === [] ) {
			return;
		}

		// Preserve any meta_query another filter already set rather than
		// clobbering it — ours narrows the result set, it does not own it.
		$existing = $query->get( 'meta_query' );
		if ( is_array( $existing ) && $existing !== [] ) {
			$query->set( 'meta_query', [ 'relation' => 'AND', $existing, $filter ] );
			return;
		}

		$query->set( 'meta_query', $filter );
	}

	/** Clause matching bookings that hold this leg. @return array<string,mixed> */
	private static function held( string $key ): array {
		return [
			'key'     => $key,
			'value'   => self::HELD_STATUSES,
			'compare' => 'IN',
		];
	}

	/**
	 * Clause matching bookings that do not hold this leg.
	 *
	 * The NOT EXISTS branch is load-bearing: a booking that never had this leg
	 * may carry no `*_status` row at all, and a bare NOT IN matches only rows
	 * that exist — it would silently drop exactly the passengers we are hunting.
	 *
	 * @return array<mixed>
	 */
	private static function notHeld( string $key ): array {
		return [
			'relation' => 'OR',
			[
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => $key,
				'value'   => self::HELD_STATUSES,
				'compare' => 'NOT IN',
			],
		];
	}
}
