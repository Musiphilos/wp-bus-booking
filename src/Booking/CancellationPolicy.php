<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Booking;

/**
 * Compute whether self-service edits / cancellations are still allowed.
 *
 * Deadline = nvf_event_start_date − nvf_cancellation_days_before (in Europe/Lisbon).
 * After the deadline only admins can modify a booking.
 */
final class CancellationPolicy {

	public static function isOpen( ?\DateTimeImmutable $now = null ): bool {
		$deadline = self::deadline();
		if ( ! $deadline ) {
			// No event date configured yet — treat as open (M3 dev mode).
			return true;
		}
		$now = $now ?: new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		return $now < $deadline;
	}

	public static function deadline(): ?\DateTimeImmutable {
		$raw = (string) \NVF\BusBooking\Support\Settings::get( 'nvf_event_start_date', '' );
		if ( $raw === '' ) {
			return null;
		}
		try {
			$start = new \DateTimeImmutable( $raw . ' 00:00:00', new \DateTimeZone( 'Europe/Lisbon' ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		$buffer = max( 0, (int) \NVF\BusBooking\Support\Settings::get( 'nvf_cancellation_days_before', 1 ) );
		return $start->modify( '-' . $buffer . ' days' )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
