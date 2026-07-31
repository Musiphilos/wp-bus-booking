<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Tests\Unit;

use NVF\BusBooking\Booking\LedgerReconciler;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the ledger reconciliation diff. No WordPress runtime:
 * we feed the required set (derived from confirmed booking meta) and the
 * existing ledger rows, and assert the insert/delete plan.
 *
 * Row shape everywhere: [ 'trip_id' => int, 'booking_id' => int, 'direction' => string ].
 * The ledger PK is (trip_id, booking_id); identity for matching is that pair.
 */
final class LedgerReconcilerTest extends TestCase {

	/** @param array<int,array{trip_id:int,booking_id:int}> $rows */
	private static function keys( array $rows ): array {
		$out = array_map( static fn( $r ) => $r['trip_id'] . ':' . $r['booking_id'], $rows );
		sort( $out );
		return $out;
	}

	public function test_missing_rows_are_inserted(): void {
		$required = [
			[ 'trip_id' => 10, 'booking_id' => 1, 'direction' => 'inbound' ],
			[ 'trip_id' => 20, 'booking_id' => 1, 'direction' => 'outbound' ],
		];
		$existing = []; // ledger empty — the migration-gap case.

		$plan = LedgerReconciler::diff( $required, $existing );

		$this->assertSame( [ '10:1', '20:1' ], self::keys( $plan['insert'] ) );
		$this->assertSame( [], $plan['delete'] );
	}

	public function test_orphan_rows_are_deleted(): void {
		$required = [];
		$existing = [
			[ 'trip_id' => 10, 'booking_id' => 1, 'direction' => 'inbound' ],
		];

		$plan = LedgerReconciler::diff( $required, $existing );

		$this->assertSame( [], $plan['insert'] );
		$this->assertSame( [ '10:1' ], self::keys( $plan['delete'] ) );
	}

	public function test_rows_already_in_sync_produce_no_changes(): void {
		$rows = [
			[ 'trip_id' => 10, 'booking_id' => 1, 'direction' => 'inbound' ],
			[ 'trip_id' => 10, 'booking_id' => 2, 'direction' => 'inbound' ],
		];

		$plan = LedgerReconciler::diff( $rows, $rows );

		$this->assertSame( [], $plan['insert'] );
		$this->assertSame( [], $plan['delete'] );
	}

	public function test_mixed_insert_and_delete(): void {
		$required = [
			[ 'trip_id' => 10, 'booking_id' => 1, 'direction' => 'inbound' ],  // stays
			[ 'trip_id' => 10, 'booking_id' => 3, 'direction' => 'inbound' ],  // insert
		];
		$existing = [
			[ 'trip_id' => 10, 'booking_id' => 1, 'direction' => 'inbound' ],  // stays
			[ 'trip_id' => 10, 'booking_id' => 2, 'direction' => 'inbound' ],  // delete (cancelled)
		];

		$plan = LedgerReconciler::diff( $required, $existing );

		$this->assertSame( [ '10:3' ], self::keys( $plan['insert'] ) );
		$this->assertSame( [ '10:2' ], self::keys( $plan['delete'] ) );
	}

	public function test_same_booking_different_trips_are_independent(): void {
		// Booking 1 keeps inbound on trip 10 but its outbound moved 20 -> 21.
		$required = [
			[ 'trip_id' => 10, 'booking_id' => 1, 'direction' => 'inbound' ],
			[ 'trip_id' => 21, 'booking_id' => 1, 'direction' => 'outbound' ],
		];
		$existing = [
			[ 'trip_id' => 10, 'booking_id' => 1, 'direction' => 'inbound' ],
			[ 'trip_id' => 20, 'booking_id' => 1, 'direction' => 'outbound' ],
		];

		$plan = LedgerReconciler::diff( $required, $existing );

		$this->assertSame( [ '21:1' ], self::keys( $plan['insert'] ) );
		$this->assertSame( [ '20:1' ], self::keys( $plan['delete'] ) );
	}

	public function test_insert_rows_preserve_direction(): void {
		$required = [ [ 'trip_id' => 20, 'booking_id' => 7, 'direction' => 'outbound' ] ];

		$plan = LedgerReconciler::diff( $required, [] );

		$this->assertCount( 1, $plan['insert'] );
		$this->assertSame( 'outbound', $plan['insert'][0]['direction'] );
	}

	// --- legToRow: the per-leg guard (the riskiest classification logic) --------

	public function test_legToRow_accepts_a_confirmed_matching_leg(): void {
		$row = LedgerReconciler::legToRow( 'confirmed', 10, true, 'OPO-IN', 'OPO-IN', 'inbound', 5 );
		$this->assertSame( [ 'trip_id' => 10, 'booking_id' => 5, 'direction' => 'inbound' ], $row );
	}

	public function test_legToRow_rejects_non_confirmed_status(): void {
		$this->assertNull( LedgerReconciler::legToRow( 'waitlist', 10, true, 'OPO-IN', 'OPO-IN', 'inbound', 5 ) );
		$this->assertNull( LedgerReconciler::legToRow( 'cancelled', 10, true, 'OPO-IN', 'OPO-IN', 'inbound', 5 ) );
		$this->assertNull( LedgerReconciler::legToRow( 'none', 10, true, 'OPO-IN', 'OPO-IN', 'inbound', 5 ) );
	}

	public function test_legToRow_rejects_missing_or_dead_trip(): void {
		// trip id absent
		$this->assertNull( LedgerReconciler::legToRow( 'confirmed', 0, false, '', 'OPO-IN', 'inbound', 5 ) );
		// trip id present but post is gone / not an nvf_trip
		$this->assertNull( LedgerReconciler::legToRow( 'confirmed', 999, false, '', 'OPO-IN', 'inbound', 5 ) );
	}

	public function test_legToRow_rejects_direction_mismatch(): void {
		// stale inbound_trip_id pointing at an outbound trip must NOT produce a row
		$this->assertNull( LedgerReconciler::legToRow( 'confirmed', 20, true, 'OPO-OUT', 'OPO-IN', 'inbound', 5 ) );
	}
}
