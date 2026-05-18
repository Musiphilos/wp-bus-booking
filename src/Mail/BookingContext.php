<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Mail;

use NVF\BusBooking\Booking\BookingReference;
use NVF\BusBooking\Domain\PostTypes;

/**
 * Build the shared template context used by booking-related emails + the PDF.
 *
 * Output shape (template authors can rely on these keys being present):
 *  - booking_ref           string
 *  - participant_name      string
 *  - participant_email     string
 *  - participant_phone     string
 *  - price_eur             float|null
 *  - legs                  array<int,array>  (one entry per active direction)
 *      - direction              'inbound'|'outbound'
 *      - direction_label        'Inbound'|'Outbound'
 *      - status                 'confirmed'|'waitlist'|'cancelled'
 *      - status_label           human label
 *      - trip_code              e.g. SHUTTLE-A
 *      - departure_human        'Thu 24 Sep · 14:30'
 *      - departure_iso          ISO-8601 with Europe/Lisbon offset (or '' if unparseable)
 *      - pickup_label           string (inbound only, '' if none)
 *      - stops                  array<int,{label,time}>
 *  - cancellation_deadline string|null  ('Sun 27 Sep · 23:59' or null)
 *  - portal_url            string
 */
final class BookingContext {

	public static function build( int $bookingId ): array {
		$post = get_post( $bookingId );
		if ( ! $post || $post->post_type !== PostTypes::BOOKING ) {
			throw new \RuntimeException( "Booking #{$bookingId} not found." );
		}

		$legs = self::legs( $bookingId );

		$ctx = [
			'booking_ref'        => BookingReference::for( $bookingId ),
			'participant_name'   => (string) get_post_meta( $bookingId, 'participant_name',  true ),
			'participant_email'  => (string) get_post_meta( $bookingId, 'participant_email', true ),
			'participant_phone'  => (string) get_post_meta( $bookingId, 'participant_phone', true ),
			'price_eur'          => self::priceForLegs( $legs ),
			'price_label'        => self::priceLabelForLegs( $legs ),
			'legs'               => $legs,
			'cancellation_deadline' => self::cancellationDeadline(),
			'portal_url'         => self::portalUrl(),
			'contact_email'      => \NVF\BusBooking\Support\Settings::contactEmail(),
		];

		return $ctx;
	}

	private static function legs( int $bookingId ): array {
		$out = [];
		foreach ( [ 'inbound' => 'Inbound', 'outbound' => 'Outbound' ] as $direction => $label ) {
			$tripKey   = $direction . '_trip_id';
			$statusKey = $direction . '_status';
			$tripId    = (int) get_post_meta( $bookingId, $tripKey, true );
			$status    = (string) get_post_meta( $bookingId, $statusKey, true );
			if ( $tripId <= 0 || $status === '' || $status === 'none' ) {
				continue;
			}
			$trip = get_post( $tripId );
			if ( ! $trip ) {
				continue;
			}
			$rawDt = (string) get_post_meta( $tripId, 'departure_datetime', true );
			$out[] = [
				'direction'       => $direction,
				'direction_label' => $label,
				'status'          => $status,
				'status_label'    => self::statusLabel( $status ),
				'trip_code'       => (string) get_post_meta( $tripId, 'trip_code', true ),
				'departure_human' => self::lisbonHuman( $rawDt ),
				'departure_iso'   => \NVF\BusBooking\Support\Time::parseStored( $rawDt )?->format( 'c' ) ?? '',
				'pickup_label'    => $direction === 'inbound' ? self::pickupLabel( (string) get_post_meta( $bookingId, 'inbound_pickup_location', true ) ) : '',
				'stops'           => self::stops( (array) get_post_meta( $tripId, 'stops', true ) ),
			];
		}
		return $out;
	}

	private static function statusLabel( string $s ): string {
		return [
			'confirmed' => 'Confirmed',
			'waitlist'  => 'Waiting list',
			'cancelled' => 'Cancelled',
		][ $s ] ?? ucfirst( $s );
	}

	private static function pickupLabel( string $code ): string {
		return [
			'airport'        => 'Porto Airport (Vodafone store)',
			'casa_da_musica' => 'Terminal Alsa/Autna — Casa da Música',
		][ $code ] ?? $code;
	}

	private static function stops( array $raw ): array {
		$out = [];
		foreach ( $raw as $row ) {
			if ( is_array( $row ) && isset( $row['label'], $row['time'] ) ) {
				$out[] = [ 'label' => (string) $row['label'], 'time' => (string) $row['time'] ];
			}
		}
		return $out;
	}

	private static function lisbonHuman( string $dt ): string {
		return \NVF\BusBooking\Support\Time::formatHuman( $dt );
	}

	/**
	 * Pick the right ticket price based on how many directions the participant
	 * actually has on file. Cancelled legs don't count toward the price.
	 *
	 * @param array<int,array> $legs
	 */
	private static function priceForLegs( array $legs ): ?float {
		$active = array_filter( $legs, static fn( $l ) => in_array( $l['status'] ?? '', [ 'confirmed', 'waitlist' ], true ) );
		$count  = count( $active );
		if ( $count === 0 ) {
			return null;
		}
		$key = $count >= 2 ? 'nvf_ticket_price_double' : 'nvf_ticket_price_single';
		$raw = \NVF\BusBooking\Support\Settings::get( $key, '' );
		if ( $raw === '' || ! is_numeric( $raw ) ) {
			// Back-compat with the legacy single-price option.
			$raw = \NVF\BusBooking\Support\Settings::get( 'nvf_ticket_price', '' );
		}
		return is_numeric( $raw ) ? (float) $raw : null;
	}

	/** @param array<int,array> $legs */
	private static function priceLabelForLegs( array $legs ): string {
		$active = array_filter( $legs, static fn( $l ) => in_array( $l['status'] ?? '', [ 'confirmed', 'waitlist' ], true ) );
		return count( $active ) >= 2 ? 'Round-trip' : 'Single';
	}

	private static function cancellationDeadline(): ?string {
		$d = \NVF\BusBooking\Booking\CancellationPolicy::deadline();
		if ( ! $d ) {
			return null;
		}
		return $d->format( 'D j M · H:i' );
	}

	private static function portalUrl(): string {
		return \NVF\BusBooking\Rest\PublicAssets::bookingPageUrl() ?: home_url( '/' );
	}
}
