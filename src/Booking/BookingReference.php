<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Booking;

/**
 * Stable, human-readable booking reference: `BUS-{id padded to 5}`.
 *
 * Used in emails, the PDF ticket, and the admin manifest. Falls back to the
 * raw post ID if anything weird happens.
 */
final class BookingReference {

	public static function for( int $bookingId ): string {
		return 'BUS-' . str_pad( (string) $bookingId, 5, '0', STR_PAD_LEFT );
	}
}
