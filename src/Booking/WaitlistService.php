<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Booking;

use NVF\BusBooking\Auth\TokenSigner;
use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Mail\BookingContext;
use NVF\BusBooking\Mail\Mailer;
use NVF\BusBooking\Support\Logger;

/**
 * Coordinator for the §4.5 simultaneous-notification waitlist:
 *
 *   notifyAll(trip, direction)
 *     - Emails every booking with status='waitlist' for (trip, direction)
 *       in FIFO order. Each email carries a unique, signed claim URL.
 *     - First click triggers an atomic SeatLedger::claim. Winner gets the
 *       seat; losers are emailed "spot taken" via notifyLosers().
 *
 *   notifyLosers(trip, direction, winnerBookingId)
 *     - Called from the ClaimController after the race resolves.
 *
 * notifyAll is idempotent per freed-seat-event via a short-lived transient so
 * a double cancel-then-cancel doesn't spam waitlisters twice for the same seat.
 */
final class WaitlistService {

	/** Lifetime of the "spot opened" claim link (hours). */
	private const CLAIM_TTL_HOURS = 24;

	/** @return int Number of "spot opened" emails sent. */
	public static function notifyAll( int $tripId, string $direction ): int {
		$lockKey = 'nvf_wl_notify_' . $tripId . '_' . $direction;
		if ( get_transient( $lockKey ) ) {
			Logger::info( 'waitlist.notify_skipped_locked', [ 'trip_id' => $tripId, 'direction' => $direction ] );
			return 0;
		}
		set_transient( $lockKey, 1, 60 );

		$waitlisters = self::loadWaitlisters( $tripId, $direction );
		if ( ! $waitlisters ) {
			return 0;
		}

		$tripCode = (string) get_post_meta( $tripId, 'trip_code', true );
		$count    = 0;
		foreach ( $waitlisters as $row ) {
			$context = self::buildContext( $row['booking_id'], $tripId, $direction, $tripCode );
			if ( ! $context ) {
				continue;
			}
			try {
				Mailer::sendSpotOpened( $row['email'], $context );
				$count++;
			} catch ( \Throwable $e ) {
				Logger::error( 'waitlist.spot_opened_failed', [
					'booking_id' => $row['booking_id'],
					'reason'     => $e->getMessage(),
				] );
			}
		}

		Logger::info( 'waitlist.notified_all', [
			'trip_id'   => $tripId,
			'trip_code' => $tripCode,
			'direction' => $direction,
			'count'     => $count,
		] );
		return $count;
	}

	public static function notifyLosers( int $tripId, string $direction, int $winnerBookingId ): int {
		$waitlisters = self::loadWaitlisters( $tripId, $direction );
		$count = 0;
		foreach ( $waitlisters as $row ) {
			if ( (int) $row['booking_id'] === $winnerBookingId ) {
				continue;
			}
			try {
				$ctx = BookingContext::build( $row['booking_id'] );
				$ctx['trip_code'] = (string) get_post_meta( $tripId, 'trip_code', true );
				Mailer::sendSpotTaken( $row['email'], $ctx );
				$count++;
			} catch ( \Throwable $e ) {
				Logger::error( 'waitlist.spot_taken_failed', [
					'booking_id' => $row['booking_id'],
					'reason'     => $e->getMessage(),
				] );
			}
		}
		Logger::info( 'waitlist.notified_losers', [
			'trip_id'    => $tripId,
			'direction'  => $direction,
			'winner_id'  => $winnerBookingId,
			'losers_emailed' => $count,
		] );
		return $count;
	}

	/**
	 * Returns the URL a waitlist email should point at for this booking.
	 * Exposed publicly so the gate test + the email template renderer share it.
	 */
	public static function claimUrl( int $bookingId, string $email, int $tripId, string $direction ): string {
		$token = TokenSigner::sign(
			$email,
			self::CLAIM_TTL_HOURS * HOUR_IN_SECONDS,
			TokenSigner::KIND_CLAIM,
			[ 't' => $tripId, 'd' => $direction, 'b' => $bookingId ]
		);
		return add_query_arg( 'token', $token, rest_url( 'nvf/v1/claim' ) );
	}

	/** @return array<int,array{booking_id:int,email:string,name:string,phone:string}> */
	private static function loadWaitlisters( int $tripId, string $direction ): array {
		$isInbound = $direction === 'inbound';
		$tripKey   = $isInbound ? 'inbound_trip_id' : 'outbound_trip_id';
		$statusKey = $isInbound ? 'inbound_status'  : 'outbound_status';

		$q = new \WP_Query( [
			'post_type'      => PostTypes::BOOKING,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => $tripKey,   'value' => $tripId,   'compare' => '=' ],
				[ 'key' => $statusKey, 'value' => 'waitlist', 'compare' => '=' ],
			],
			'orderby' => 'date',
			'order'   => 'ASC',
		] );
		$out = [];
		foreach ( $q->posts as $post ) {
			$out[] = [
				'booking_id' => (int) $post->ID,
				'email'      => (string) get_post_meta( $post->ID, 'participant_email', true ),
				'name'       => (string) get_post_meta( $post->ID, 'participant_name',  true ),
				'phone'      => (string) get_post_meta( $post->ID, 'participant_phone', true ),
			];
		}
		wp_reset_postdata();
		return $out;
	}

	private static function buildContext( int $bookingId, int $tripId, string $direction, string $tripCode ): ?array {
		try {
			$ctx = BookingContext::build( $bookingId );
		} catch ( \Throwable $e ) {
			Logger::error( 'waitlist.context_failed', [ 'booking_id' => $bookingId, 'reason' => $e->getMessage() ] );
			return null;
		}
		$email = $ctx['participant_email'];
		$ctx['trip_code']   = $tripCode;
		$ctx['trip_id']     = $tripId;
		$ctx['direction']   = $direction;
		$ctx['claim_url']   = self::claimUrl( $bookingId, $email, $tripId, $direction );
		$ctx['ttl_hours']   = self::CLAIM_TTL_HOURS;
		return $ctx;
	}
}
