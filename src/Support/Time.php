<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Support;

/**
 * Single source of truth for timestamps.
 *
 * All plugin-owned timestamps (booking audit rows, seat-ledger entries,
 * email-uniqueness rows, trip departures, log lines) are stored and compared
 * in Europe/Lisbon. This matches the WordPress site's configured timezone, so
 * what the admin types is what gets stored is what the frontend renders.
 */
final class Time {

	public const TZ = 'Europe/Lisbon';

	public static function zone(): \DateTimeZone {
		return new \DateTimeZone( self::TZ );
	}

	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', self::zone() );
	}

	/**
	 * `Y-m-d H:i:s` in Lisbon. Drop-in replacement for `current_time('mysql', true)`.
	 */
	public static function nowMysql(): string {
		return self::now()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * ISO-8601 with Lisbon offset. Drop-in replacement for `gmdate('c')`.
	 */
	public static function nowIso(): string {
		return self::now()->format( 'c' );
	}

	/**
	 * Parse a stored wall-clock value (e.g. `departure_datetime`) as Lisbon time.
	 * Returns null on malformed input.
	 */
	public static function parseStored( string $dt ): ?\DateTimeImmutable {
		if ( '' === $dt ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $dt, self::zone() );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Human-readable `D j M · H:i` from a stored Lisbon wall-clock string.
	 * Empty string in → empty string out.
	 */
	public static function formatHuman( string $dt ): string {
		$d = self::parseStored( $dt );
		return $d ? $d->format( 'D j M · H:i' ) : '';
	}
}
