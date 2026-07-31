<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Booking;

use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Support\Logger;
use NVF\BusBooking\Support\Time;

/**
 * Rebuilds the seat ledger from the confirmed-booking meta so the two never
 * drift apart.
 *
 * Why this exists: "confirmed seats" has historically had two sources of truth —
 * the per-direction `*_status = confirmed` meta on nvf_booking posts (what the
 * Trips admin column counts) and the wp_nvf_seat_ledger rows (what the booking
 * page, Dashboard and Manifest count). They agree only if every confirmed leg
 * has exactly one ledger row. They can fall out of sync because the ledger is
 * populated only on activation (a dev→prod file copy leaves older confirmed
 * bookings with no row) and because a claim can, on a DB error, stamp the meta
 * confirmed without a row landing. A short ledger both misreports availability
 * and — because the capacity guard counts ledger rows — risks overbooking.
 *
 * The fix is a reconciliation: treat the confirmed booking legs as the truth and
 * make the ledger match — insert missing rows, drop orphans. It runs once per
 * schema version on `init` (self-healing, like the signing secret) and is also
 * exposed as `wp nvf reconcile-ledger`.
 *
 * Note: reconcile MIRRORS the confirmed meta, it is not a capacity enforcer. If
 * the meta holds more confirmed legs than a trip's capacity (e.g. admin override
 * bookings), every one gets a row — the meta is the arbiter of who is booked.
 */
final class LedgerReconciler {

	/**
	 * Bump when the reconciliation logic changes in a way that should re-run on
	 * existing installs. Stored in the `nvf_ledger_sync` option; while it differs
	 * from this constant the sync runs once on the next request.
	 */
	private const VERSION = 1;

	private const OPTION = 'nvf_ledger_sync';

	public static function register(): void {
		// `init` (front + admin + REST): the heal must close the overbooking
		// window on the PUBLIC booking path, not only when a team member visits
		// wp-admin — the file-copy deploy model never runs activation or WP-CLI,
		// so nothing else guarantees the ledger is populated on prod. The option
		// is autoloaded, so once healed the steady-state check is a free
		// alloptions lookup; the scan itself runs exactly once per version.
		add_action( 'init', [ self::class, 'maybeSync' ] );

		// Per-booking sync. The plugin's own flows (booking, admin-add, cancel,
		// waitlist promote) keep the ledger in step themselves. But the booking
		// CPT is editable in the WP post editor, and changing the Trip/Status
		// fields there — or trashing/restoring/deleting a booking — writes meta
		// without touching the ledger. These hooks reconcile that one booking so
		// a manual edit can never silently desync the seat counts.
		//
		// save_post at a late priority (100) so Meta Box has already persisted
		// its fields by the time we read them. update_post_meta (how the service
		// classes write) does NOT fire save_post, so this never runs mid-operation
		// and can't fight the atomic claim.
		add_action( 'save_post', [ self::class, 'onSave' ], 100 );
		// Trash/restore normally route through wp_update_post and thus fire
		// save_post already; these are idempotent belt-and-suspenders in case a
		// caller trashes without it. before_delete_post is essential — no
		// save_post fires on permanent delete.
		add_action( 'trashed_post', [ self::class, 'reconcileBooking' ] );
		add_action( 'untrashed_post', [ self::class, 'reconcileBooking' ] );
		add_action( 'before_delete_post', [ self::class, 'onDelete' ] );
	}

	/** save_post handler: reconcile the saved booking (skips autosaves/revisions). */
	public static function onSave( int $postId ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $postId )
			|| wp_is_post_autosave( $postId ) ) {
			return;
		}
		self::reconcileBooking( $postId );
	}

	/** before_delete_post handler: drop all ledger rows for a booking being removed. */
	public static function onDelete( int $postId ): void {
		if ( get_post_type( $postId ) === PostTypes::BOOKING ) {
			SeatLedger::releaseAllForBooking( $postId );
		}
	}

	/**
	 * Reconcile a single booking's ledger rows to its current confirmed legs.
	 * No-op for non-bookings. Idempotent. Used by the editor/trash/restore hooks
	 * and callable directly. A non-publish booking (draft/trash) holds no seats,
	 * so its rows are released.
	 */
	public static function reconcileBooking( int $bookingId ): void {
		if ( get_post_type( $bookingId ) !== PostTypes::BOOKING ) {
			return;
		}
		$plan = self::diff( self::legsForBooking( $bookingId ), self::existingForBooking( $bookingId ) );
		if ( ! $plan['insert'] && ! $plan['delete'] ) {
			return;
		}
		global $wpdb;
		$wpdb->suppress_errors( true );
		foreach ( $plan['insert'] as $row ) {
			self::insertRow( $row );
		}
		foreach ( $plan['delete'] as $row ) {
			self::deleteRow( $row );
		}
		$wpdb->suppress_errors( false );
		Logger::info( 'ledger.booking_synced', [
			'booking_id' => $bookingId,
			'inserted'   => count( $plan['insert'] ),
			'deleted'    => count( $plan['delete'] ),
		] );
	}

	public static function maybeSync(): void {
		if ( (int) get_option( self::OPTION, 0 ) === self::VERSION ) {
			return;
		}
		try {
			$result = self::run();
			// Autoloaded (3rd arg true) so the guard check above never costs a
			// query once healed.
			update_option( self::OPTION, self::VERSION, true );
			Logger::info( 'ledger.reconciled', $result );
		} catch ( \Throwable $e ) {
			// Leave the option unset so the next request retries. The set is tiny
			// (bounded by booking count) so a transient failure self-corrects.
			Logger::error( 'ledger.reconcile_failed', [ 'reason' => $e->getMessage() ] );
		}
	}

	/**
	 * Compute the ledger changes needed to match the required set. Pure — no I/O,
	 * so it is unit-testable. Identity is the (trip_id, booking_id) pair, which is
	 * the ledger primary key.
	 *
	 * @param array<int,array{trip_id:int,booking_id:int,direction:string}> $required Confirmed legs (the truth).
	 * @param array<int,array{trip_id:int,booking_id:int,direction:string}> $existing Current ledger rows.
	 *
	 * @return array{insert:array<int,array{trip_id:int,booking_id:int,direction:string}>,delete:array<int,array{trip_id:int,booking_id:int,direction:string}>}
	 */
	public static function diff( array $required, array $existing ): array {
		$key = static fn( array $r ): string => (int) $r['trip_id'] . ':' . (int) $r['booking_id'];

		$existingKeys = [];
		foreach ( $existing as $row ) {
			$existingKeys[ $key( $row ) ] = true;
		}
		$requiredKeys = [];
		foreach ( $required as $row ) {
			$requiredKeys[ $key( $row ) ] = true;
		}

		$insert = [];
		foreach ( $required as $row ) {
			if ( ! isset( $existingKeys[ $key( $row ) ] ) ) {
				$insert[] = $row;
			}
		}

		$delete = [];
		foreach ( $existing as $row ) {
			if ( ! isset( $requiredKeys[ $key( $row ) ] ) ) {
				$delete[] = $row;
			}
		}

		return [ 'insert' => $insert, 'delete' => $delete ];
	}

	/**
	 * Reconcile the live ledger against confirmed booking meta.
	 *
	 * @return array{confirmed_legs:int,inserted:int,deleted:int}
	 */
	public static function run(): array {
		global $wpdb;
		$required = self::gatherRequired();
		$existing = self::gatherExisting();
		$plan     = self::diff( $required, $existing );

		// Two requests can both pass the version guard right after a deploy and
		// run concurrently; the diff is idempotent but the loser hits benign PK
		// collisions on insert. Suppress so those don't spam the DB error log.
		$wpdb->suppress_errors( true );
		$inserted = 0;
		foreach ( $plan['insert'] as $row ) {
			$inserted += self::insertRow( $row );
		}
		$deleted = 0;
		foreach ( $plan['delete'] as $row ) {
			$deleted += self::deleteRow( $row );
		}
		$wpdb->suppress_errors( false );

		return [
			'confirmed_legs' => count( $required ),
			'inserted'       => $inserted,
			'deleted'        => $deleted,
		];
	}

	/**
	 * Every confirmed booking leg, as a required ledger row. A leg counts when its
	 * `*_status` is `confirmed`, its `*_trip_id` is a live nvf_trip, and the trip's
	 * direction matches the leg (inbound legs on OPO-IN trips, outbound on OPO-OUT)
	 * — guarding against stale meta pointing at a deleted or repurposed trip.
	 *
	 * @return array<int,array{trip_id:int,booking_id:int,direction:string}>
	 */
	private static function gatherRequired(): array {
		$bookingIds = get_posts( [
			'post_type'      => PostTypes::BOOKING,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		// Prime the meta cache in one query rather than N lazy loads.
		if ( $bookingIds ) {
			update_meta_cache( 'post', $bookingIds );
		}

		$required = [];
		foreach ( $bookingIds as $bookingId ) {
			foreach ( self::legsForBooking( (int) $bookingId ) as $row ) {
				$required[] = $row;
			}
		}
		return $required;
	}

	/**
	 * The confirmed ledger rows a single booking should have. Empty when the
	 * booking is not published (draft/trash hold no seats). Shared by the full
	 * scan and the per-booking sync.
	 *
	 * @return array<int,array{trip_id:int,booking_id:int,direction:string}>
	 */
	private static function legsForBooking( int $bookingId ): array {
		if ( get_post_status( $bookingId ) !== 'publish' ) {
			return [];
		}
		$legs = [
			BookingService::DIRECTION_INBOUND  => [ 'status' => 'inbound_status',  'trip' => 'inbound_trip_id',  'expect' => 'OPO-IN' ],
			BookingService::DIRECTION_OUTBOUND => [ 'status' => 'outbound_status', 'trip' => 'outbound_trip_id', 'expect' => 'OPO-OUT' ],
		];

		$rows = [];
		foreach ( $legs as $direction => $keys ) {
			$status     = (string) get_post_meta( $bookingId, $keys['status'], true );
			$tripId     = (int) get_post_meta( $bookingId, $keys['trip'], true );
			$tripIsLive = $tripId > 0 && get_post_type( $tripId ) === PostTypes::TRIP;
			$tripDir    = $tripIsLive ? (string) get_post_meta( $tripId, 'direction', true ) : '';

			$row = self::legToRow( $status, $tripId, $tripIsLive, $tripDir, $keys['expect'], $direction, $bookingId );
			if ( $row !== null ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/** @return array<int,array{trip_id:int,booking_id:int,direction:string}> */
	private static function existingForBooking( int $bookingId ): array {
		global $wpdb;
		$table = SeatLedger::tableName();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT trip_id, booking_id, direction FROM {$table} WHERE booking_id = %d", $bookingId ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return [];
		}
		return array_map(
			static fn( array $r ): array => [
				'trip_id'    => (int) $r['trip_id'],
				'booking_id' => (int) $r['booking_id'],
				'direction'  => (string) $r['direction'],
			],
			$rows
		);
	}

	/**
	 * Pure decision: does one booking leg require a ledger row, and which one?
	 *
	 * A leg qualifies only when it is confirmed, its trip is a live nvf_trip, and
	 * the trip's direction matches the leg (inbound legs on OPO-IN trips, outbound
	 * on OPO-OUT). The direction match is what makes it impossible for a single
	 * booking to yield two rows on the same trip even when meta is corrupt, so
	 * `SeatLedger::countConfirmed($tripId)` — which counts all rows for a trip —
	 * can never be cross-direction-contaminated. No I/O, so it is unit-testable.
	 *
	 * @return array{trip_id:int,booking_id:int,direction:string}|null
	 */
	public static function legToRow( string $status, int $tripId, bool $tripIsLive, string $tripDirection, string $expectedDirection, string $legDirection, int $bookingId ): ?array {
		if ( $status !== 'confirmed' ) {
			return null;
		}
		if ( $tripId <= 0 || ! $tripIsLive ) {
			return null;
		}
		if ( $tripDirection !== $expectedDirection ) {
			return null;
		}
		return [ 'trip_id' => $tripId, 'booking_id' => $bookingId, 'direction' => $legDirection ];
	}

	/** @return array<int,array{trip_id:int,booking_id:int,direction:string}> */
	private static function gatherExisting(): array {
		global $wpdb;
		$table = SeatLedger::tableName();
		$rows  = $wpdb->get_results( "SELECT trip_id, booking_id, direction FROM {$table}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		return array_map(
			static fn( array $r ): array => [
				'trip_id'    => (int) $r['trip_id'],
				'booking_id' => (int) $r['booking_id'],
				'direction'  => (string) $r['direction'],
			],
			$rows
		);
	}

	/** @param array{trip_id:int,booking_id:int,direction:string} $row */
	private static function insertRow( array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert( SeatLedger::tableName(), [
			'trip_id'    => $row['trip_id'],
			'booking_id' => $row['booking_id'],
			'direction'  => $row['direction'],
			'created_at' => Time::nowMysql(),
		], [ '%d', '%d', '%s', '%s' ] );
		return $ok ? 1 : 0;
	}

	/** @param array{trip_id:int,booking_id:int,direction:string} $row */
	private static function deleteRow( array $row ): int {
		global $wpdb;
		$ok = $wpdb->delete( SeatLedger::tableName(), [
			'trip_id'    => $row['trip_id'],
			'booking_id' => $row['booking_id'],
		], [ '%d', '%d' ] );
		return $ok ? 1 : 0;
	}
}
