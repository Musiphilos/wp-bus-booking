<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Tests\Unit;

use NVF\BusBooking\Admin\BookingLegFilter;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the leg-coverage `meta_query` builder. No WordPress
 * runtime — `metaQueryFor()` only shapes an array.
 *
 * The invariant under test: a leg is "held" when its status is `confirmed` or
 * `waitlist`. Every other state — `none`, `cancelled`, empty string, or no meta
 * row at all — is "not held". The `NOT EXISTS` branch is the load-bearing part:
 * a booking that never had a leg may carry no `*_status` row, and a bare
 * `NOT IN` would silently drop it from the results.
 */
final class BookingLegFilterTest extends TestCase {

	public function test_unknown_mode_yields_no_filter(): void {
		$this->assertSame( [], BookingLegFilter::metaQueryFor( 'nonsense' ) );
	}

	public function test_all_mode_yields_no_filter(): void {
		$this->assertSame( [], BookingLegFilter::metaQueryFor( BookingLegFilter::MODE_ALL ) );
	}

	public function test_empty_mode_yields_no_filter(): void {
		$this->assertSame( [], BookingLegFilter::metaQueryFor( '' ) );
	}

	public function test_return_only_requires_outbound_held_and_inbound_not(): void {
		$query = BookingLegFilter::metaQueryFor( BookingLegFilter::MODE_RETURN_ONLY );

		$this->assertSame( 'AND', $query['relation'] );
		$this->assertSame( self::held( 'outbound_status' ), $query[0] );
		$this->assertSame( self::notHeld( 'inbound_status' ), $query[1] );
	}

	public function test_inbound_only_requires_inbound_held_and_outbound_not(): void {
		$query = BookingLegFilter::metaQueryFor( BookingLegFilter::MODE_INBOUND_ONLY );

		$this->assertSame( 'AND', $query['relation'] );
		$this->assertSame( self::held( 'inbound_status' ), $query[0] );
		$this->assertSame( self::notHeld( 'outbound_status' ), $query[1] );
	}

	public function test_both_legs_requires_each_leg_held(): void {
		$query = BookingLegFilter::metaQueryFor( BookingLegFilter::MODE_BOTH );

		$this->assertSame( 'AND', $query['relation'] );
		$this->assertSame( self::held( 'inbound_status' ), $query[0] );
		$this->assertSame( self::held( 'outbound_status' ), $query[1] );
	}

	public function test_no_legs_requires_neither_leg_held(): void {
		$query = BookingLegFilter::metaQueryFor( BookingLegFilter::MODE_NO_LEGS );

		$this->assertSame( 'AND', $query['relation'] );
		$this->assertSame( self::notHeld( 'inbound_status' ), $query[0] );
		$this->assertSame( self::notHeld( 'outbound_status' ), $query[1] );
	}

	/**
	 * A cancelled leg must not be treated as held — that is what puts a
	 * cancelled-inbound passenger into "Return only" rather than "Both legs".
	 *
	 * Asserts on real `metaQueryFor()` output, not on this file's helpers:
	 * widening HELD_STATUSES to include `cancelled` must fail here.
	 */
	public function test_cancelled_is_not_a_held_status(): void {
		$clause = BookingLegFilter::metaQueryFor( BookingLegFilter::MODE_BOTH )[0];

		$this->assertSame( 'IN', $clause['compare'] );
		$this->assertSame( [ 'confirmed', 'waitlist' ], $clause['value'] );
		$this->assertNotContains( 'cancelled', $clause['value'] );
		$this->assertNotContains( 'none', $clause['value'] );
	}

	/**
	 * Bookings with no `*_status` row at all must still match "not held", so
	 * every not-held clause needs the NOT EXISTS branch beside the NOT IN.
	 * `BookingService` writes `inbound_status` only when an inbound trip was
	 * chosen, so outbound-only bookings genuinely have no row to compare.
	 */
	public function test_not_held_matches_a_missing_meta_row(): void {
		$group = BookingLegFilter::metaQueryFor( BookingLegFilter::MODE_RETURN_ONLY )[1];

		$this->assertSame( 'OR', $group['relation'] );
		$this->assertSame( 'NOT EXISTS', $group[0]['compare'] );
		$this->assertSame( 'inbound_status', $group[0]['key'] );
		$this->assertSame( 'NOT IN', $group[1]['compare'] );
		$this->assertSame( 'inbound_status', $group[1]['key'] );
	}

	/**
	 * Every not-held clause must carry the NOT EXISTS branch — a mode that
	 * dropped it would silently lose the passengers the feature exists to find.
	 */
	public function test_every_not_held_clause_tolerates_a_missing_row(): void {
		$modes = [
			BookingLegFilter::MODE_RETURN_ONLY  => 1,
			BookingLegFilter::MODE_INBOUND_ONLY => 1,
			BookingLegFilter::MODE_NO_LEGS      => 0,
		];

		foreach ( $modes as $mode => $index ) {
			$group = BookingLegFilter::metaQueryFor( $mode )[ $index ];

			$this->assertSame( 'OR', $group['relation'], "Mode '{$mode}' clause {$index} is not an OR group." );
			$this->assertSame(
				'NOT EXISTS',
				$group[0]['compare'],
				"Mode '{$mode}' would drop bookings that have no status row at all."
			);
		}
	}

	public function test_every_offered_mode_is_understood(): void {
		foreach ( BookingLegFilter::modeKeys() as $mode ) {
			if ( $mode === BookingLegFilter::MODE_ALL ) {
				continue;
			}
			$this->assertNotSame(
				[],
				BookingLegFilter::metaQueryFor( $mode ),
				"Mode '{$mode}' is offered in the dropdown but builds no filter."
			);
		}
	}

	public function test_mode_keys_are_unique_and_slug_safe(): void {
		$keys = BookingLegFilter::modeKeys();

		$this->assertSame( $keys, array_values( array_unique( $keys ) ) );
		foreach ( $keys as $key ) {
			// currentMode() passes the URL value through sanitize_key(), which
			// lowercases and strips anything outside [a-z0-9_-] — a mode slug
			// that does not survive that round trip could never be selected.
			$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $key );
		}
	}

	/** @return array<string,mixed> */
	private static function held( string $key ): array {
		return [
			'key'     => $key,
			'value'   => [ 'confirmed', 'waitlist' ],
			'compare' => 'IN',
		];
	}

	/** @return array<string,mixed> */
	private static function notHeld( string $key ): array {
		return [
			'relation' => 'OR',
			[
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => $key,
				'value'   => [ 'confirmed', 'waitlist' ],
				'compare' => 'NOT IN',
			],
		];
	}
}
