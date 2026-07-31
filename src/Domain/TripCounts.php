<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Domain;

use NVF\BusBooking\Booking\SeatLedger;

/**
 * Read-side helpers for counting bookings against a trip. Cheap enough to
 * call from admin columns without caching for now; if the bookings list
 * grows we can swap in a transient cache invalidated on save_post_nvf_booking.
 */
final class TripCounts {

	/** @return array{confirmed:int,waitlist:int,capacity:int,direction:string} */
	public static function forTrip( int $tripId ): array {
		$direction = (string) get_post_meta( $tripId, 'direction', true );
		$capacity  = (int) ( get_post_meta( $tripId, 'capacity', true ) ?: 0 );

		return [
			// Confirmed seats come from the seat ledger — the SAME source the
			// booking page, Dashboard and Manifest use — so the Trips column can
			// never disagree with them. The ledger is kept honest by
			// LedgerReconciler; waitlist has no ledger representation and stays
			// meta-derived.
			'confirmed' => SeatLedger::countConfirmed( $tripId ),
			'waitlist'  => self::countByStatus( $tripId, $direction, 'waitlist' ),
			'capacity'  => $capacity,
			'direction' => $direction,
		];
	}

	private static function countByStatus( int $tripId, string $direction, string $status ): int {
		$isInbound = str_starts_with( $direction, 'OPO-IN' );
		$tripKey   = $isInbound ? 'inbound_trip_id' : 'outbound_trip_id';
		$statusKey = $isInbound ? 'inbound_status'  : 'outbound_status';

		$q = new \WP_Query( [
			'post_type'      => PostTypes::BOOKING,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => $tripKey,   'value' => $tripId,  'compare' => '=' ],
				[ 'key' => $statusKey, 'value' => $status,  'compare' => '=' ],
			],
		] );
		$count = is_array( $q->posts ) ? count( $q->posts ) : 0;
		wp_reset_postdata();
		return $count;
	}
}
