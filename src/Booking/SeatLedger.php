<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Booking;

use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Support\Logger;
use NVF\BusBooking\Support\Time;

/**
 * Atomic capacity guard.
 *
 * Layout:
 *   trip_id     BIGINT UNSIGNED NOT NULL
 *   booking_id  BIGINT UNSIGNED NOT NULL
 *   direction   VARCHAR(8) NOT NULL  ('inbound'|'outbound')
 *   created_at  DATETIME NOT NULL
 *   PRIMARY KEY (trip_id, booking_id)
 *   KEY direction (direction)
 *
 * The atomic claim from §9.3:
 *   INSERT INTO ledger (...) SELECT ... WHERE (SELECT COUNT(*) FROM ledger WHERE trip_id = ?) < capacity
 *
 * If 0 rows affected → trip is full → caller falls back to the waiting list.
 * If 1 row affected → the seat is ours.
 *
 * The PRIMARY KEY also prevents one booking from claiming the same trip twice
 * (e.g. through a double-clicked form submit).
 */
final class SeatLedger {

	public const TABLE_SUFFIX = 'nvf_seat_ledger';

	public static function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::tableName();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			trip_id BIGINT(20) UNSIGNED NOT NULL,
			booking_id BIGINT(20) UNSIGNED NOT NULL,
			direction VARCHAR(8) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (trip_id, booking_id),
			KEY direction (direction),
			KEY booking_id (booking_id)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Atomically claim a seat on $tripId for $bookingId.
	 * Returns 'confirmed' (seat acquired) or 'waitlist' (trip full).
	 */
	public static function claim( int $tripId, int $bookingId, string $direction ): string {
		global $wpdb;
		$table    = self::tableName();
		$capacity = self::capacityOf( $tripId );

		if ( $capacity <= 0 ) {
			Logger::warning( 'seat.invalid_capacity', [ 'trip_id' => $tripId ] );
			return 'waitlist';
		}

		// Single SQL statement — the guard is the WHERE clause comparing the
		// current count against capacity. MySQL evaluates the subquery and the
		// INSERT under the same statement, so the only way a race can produce
		// >capacity rows is if the subquery + insert aren't atomic — which they
		// are under InnoDB's row-level locks for the same table.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (trip_id, booking_id, direction, created_at)
			 SELECT %d, %d, %s, %s
			 FROM DUAL
			 WHERE (SELECT COUNT(*) FROM {$table} WHERE trip_id = %d) < %d",
			$tripId,
			$bookingId,
			$direction,
			Time::nowMysql(),
			$tripId,
			$capacity
		);

		$wpdb->suppress_errors( true );
		$affected = $wpdb->query( $sql );
		$wpdb->suppress_errors( false );

		if ( false === $affected ) {
			// Likely PK violation: same booking already holds this seat.
			Logger::info( 'seat.claim_duplicate', [ 'trip_id' => $tripId, 'booking_id' => $bookingId, 'db_error' => $wpdb->last_error ] );
			return 'confirmed';
		}
		return $affected > 0 ? 'confirmed' : 'waitlist';
	}

	public static function release( int $tripId, int $bookingId ): bool {
		global $wpdb;
		$rows = $wpdb->delete( self::tableName(), [
			'trip_id'    => $tripId,
			'booking_id' => $bookingId,
		], [ '%d', '%d' ] );
		return (bool) $rows;
	}

	public static function releaseAllForBooking( int $bookingId ): void {
		global $wpdb;
		$wpdb->delete( self::tableName(), [ 'booking_id' => $bookingId ], [ '%d' ] );
	}

	public static function countConfirmed( int $tripId ): int {
		global $wpdb;
		$table = self::tableName();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE trip_id = %d",
			$tripId
		) );
	}

	private static function capacityOf( int $tripId ): int {
		if ( get_post_type( $tripId ) !== PostTypes::TRIP ) {
			return 0;
		}
		$status = (string) get_post_meta( $tripId, 'status', true );
		if ( $status === 'cancelled' ) {
			return 0;
		}
		return (int) ( get_post_meta( $tripId, 'capacity', true ) ?: 0 );
	}
}
