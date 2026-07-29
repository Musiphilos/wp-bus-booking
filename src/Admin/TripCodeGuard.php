<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Admin;

use NVF\BusBooking\Domain\PostTypes;

/**
 * Warns loudly when two or more trips share a trip_code.
 *
 * Duplicate codes are the failure mode behind the "availability doesn't count
 * bookings" bug: the booking page (/trips) lists one published trip per code,
 * ordered by departure, while existing bookings and their seat-ledger rows can
 * hang off a *different* post that happens to share the code (e.g. after a
 * re-seed or a trip being rebuilt). The two silently diverge — and because
 * availability is counted per trip-post, seats look free when they are not.
 *
 * There is no clean way to hard-block a WordPress save without surprising the
 * editor, so instead of preventing the save we surface the condition on the
 * Trips screens: an admin notice listing every code used by more than one trip,
 * with a link to each offending post so the operator can delete or renumber the
 * extras. The scan catches duplicates whatever created them (editor, CLI seed,
 * import). Runs only on the nvf_trip list and edit screens.
 */
final class TripCodeGuard {

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'maybeWarn' ] );
	}

	public static function maybeWarn(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== PostTypes::TRIP ) {
			return;
		}

		$duplicates = self::duplicateCodes();
		if ( ! $duplicates ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Bus Booking: duplicate trip codes detected.', 'nvf-bus-booking' )
			. '</strong> '
			. esc_html__( 'Two or more trips share the same code. The booking page shows one of them while bookings and seat availability can live on another, so seats may look free when they are not. Keep exactly one trip per code — delete or renumber the extras.', 'nvf-bus-booking' )
			. '</p><ul style="list-style:disc;margin-left:20px;">';

		foreach ( $duplicates as $code => $ids ) {
			$links = array_map(
				static function ( int $id ): string {
					$label = sprintf( '#%d (%s)', $id, get_post_status( $id ) ?: 'unknown' );
					$edit  = get_edit_post_link( $id );
					return $edit
						? '<a href="' . esc_url( $edit ) . '">' . esc_html( $label ) . '</a>'
						: esc_html( $label );
				},
				$ids
			);
			// $links entries are individually escaped above.
			printf( '<li><code>%s</code> — %s</li>', esc_html( $code ), implode( ', ', $links ) );
		}

		echo '</ul></div>';
	}

	/**
	 * Trip codes used by more than one non-trashed trip.
	 *
	 * @return array<string,int[]> code => [ postId, … ]
	 */
	private static function duplicateCodes(): array {
		$ids = get_posts( [
			'post_type'      => PostTypes::TRIP,
			'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$byCode = [];
		foreach ( $ids as $id ) {
			$code = trim( (string) get_post_meta( (int) $id, 'trip_code', true ) );
			if ( $code === '' ) {
				continue;
			}
			$byCode[ $code ][] = (int) $id;
		}

		return array_filter( $byCode, static fn( array $group ): bool => count( $group ) > 1 );
	}
}
