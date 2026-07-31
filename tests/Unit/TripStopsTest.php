<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Tests\Unit;

use NVF\BusBooking\Domain\TripStops;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for deriving pickup options from a trip's stops. The rule:
 * pickup points are every stop EXCEPT the last (the last is the arrival), with
 * empty-label rows dropped. No WordPress runtime.
 */
final class TripStopsTest extends TestCase {

	public function test_all_but_last_stop_are_pickups(): void {
		$stops = [
			[ 'label' => 'Airport', 'time' => '14:30' ],
			[ 'label' => 'Casa da Música', 'time' => '14:50' ],
			[ 'label' => 'Hotel', 'time' => '16:00' ],
		];
		$pickups = TripStops::pickupsFrom( $stops );

		$this->assertSame( [ 'Airport', 'Casa da Música' ], array_column( $pickups, 'label' ) );
		$this->assertSame( '14:30', $pickups[0]['time'] );
	}

	public function test_single_stop_yields_no_pickups(): void {
		$this->assertSame( [], TripStops::pickupsFrom( [ [ 'label' => 'Hotel', 'time' => '16:00' ] ] ) );
	}

	public function test_empty_stops_yield_no_pickups(): void {
		$this->assertSame( [], TripStops::pickupsFrom( [] ) );
	}

	public function test_empty_label_rows_are_dropped_before_slicing(): void {
		// A blank clone row must not count as the "last" stop and must not appear.
		$stops = [
			[ 'label' => 'Airport', 'time' => '14:30' ],
			[ 'label' => 'Casa da Música', 'time' => '14:50' ],
			[ 'label' => 'Hotel', 'time' => '16:00' ],
			[ 'label' => '', 'time' => '' ],
		];
		$pickups = TripStops::pickupsFrom( $stops );

		$this->assertSame( [ 'Airport', 'Casa da Música' ], array_column( $pickups, 'label' ) );
	}

	public function test_order_is_preserved(): void {
		$stops = [
			[ 'label' => 'B', 'time' => '10:00' ],
			[ 'label' => 'A', 'time' => '10:10' ],
			[ 'label' => 'C', 'time' => '10:20' ],
		];
		$this->assertSame( [ 'B', 'A' ], array_column( TripStops::pickupsFrom( $stops ), 'label' ) );
	}

	public function test_non_array_and_missing_keys_are_ignored(): void {
		$stops = [
			[ 'label' => 'Airport', 'time' => '14:30' ],
			'garbage',
			[ 'time' => '15:00' ],           // no label
			[ 'label' => 'Hotel', 'time' => '16:00' ],
		];
		$this->assertSame( [ 'Airport' ], array_column( TripStops::pickupsFrom( $stops ), 'label' ) );
	}
}
