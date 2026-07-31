<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Domain;

/**
 * Derives a trip's pickup points from its Stops.
 *
 * A trip's stops are an ordered list of `{label, time}`. For an inbound trip the
 * passenger boards at one of the earlier stops and rides to the final stop (the
 * arrival / hotel), so the pickup options are every stop EXCEPT the last. Blank
 * clone rows (empty label) are dropped first so they can't masquerade as the
 * arrival or appear as an option.
 *
 * This is the single source of the "all but last" rule — the booking page, the
 * admin Add Booking form, and server-side validation all read it here, so they
 * can never disagree about what counts as a pickup.
 */
final class TripStops {

	/** Pickup options for a trip: `[{label,time}, …]`, all but the final stop. */
	public static function pickups( int $tripId ): array {
		return self::pickupsFrom( self::rawStops( $tripId ) );
	}

	/** Just the pickup labels — the whitelist used to validate a submitted pickup. */
	public static function pickupLabels( int $tripId ): array {
		return array_column( self::pickups( $tripId ), 'label' );
	}

	/**
	 * Pure: given raw stop rows, return the pickup options (all but the last,
	 * empty-label rows dropped, order preserved). No I/O — unit-tested.
	 *
	 * @param array<int,mixed> $stops
	 * @return array<int,array{label:string,time:string}>
	 */
	public static function pickupsFrom( array $stops ): array {
		$clean = [];
		foreach ( $stops as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';
			if ( $label === '' ) {
				continue;
			}
			$clean[] = [
				'label' => $label,
				'time'  => isset( $row['time'] ) ? (string) $row['time'] : '',
			];
		}
		// Drop the final stop (the arrival). array_slice on an empty or single
		// element list yields [].
		return $clean ? array_slice( $clean, 0, -1 ) : [];
	}

	/** @return array<int,mixed> */
	private static function rawStops( int $tripId ): array {
		$stops = get_post_meta( $tripId, 'stops', true );
		return is_array( $stops ) ? $stops : [];
	}
}
