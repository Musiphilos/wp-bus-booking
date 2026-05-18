<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Booking;

use NVF\BusBooking\Auth\ElementorLookup;
use NVF\BusBooking\Domain\EmailUniqueness;
use NVF\BusBooking\Domain\MetaBoxes;
use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Integrations\GoogleSheetsWebhook;
use NVF\BusBooking\Mail\BookingContext;
use NVF\BusBooking\Mail\Mailer;
use NVF\BusBooking\Support\Logger;
use NVF\BusBooking\Support\Time;

/**
 * Domain service for the booking lifecycle.
 *
 * Responsibilities:
 *  - Atomically claim seats via SeatLedger.
 *  - Reflect the result onto the nvf_booking CPT (per-direction status meta).
 *  - Append history entries on every transition.
 *  - Enforce the cancellation deadline on self-service edits.
 *
 * Status values per direction (mirrors spec §9.1):
 *  - 'none'      — direction not selected
 *  - 'confirmed' — seat held in SeatLedger
 *  - 'waitlist'  — full when claim ran; position assigned
 *  - 'cancelled' — previously confirmed/waitlisted, then cancelled
 */
final class BookingService {

	public const DIRECTION_INBOUND  = 'inbound';
	public const DIRECTION_OUTBOUND = 'outbound';

	/**
	 * @return array{
	 *   booking_id:int,
	 *   inbound:array{status:string,trip_id:?int,pickup:?string,waitlist_position:?int},
	 *   outbound:array{status:string,trip_id:?int,waitlist_position:?int},
	 * }
	 */
	public static function snapshot( string $email ): ?array {
		$id = self::findBookingId( $email );
		if ( ! $id ) {
			return null;
		}
		return [
			'booking_id' => $id,
			'inbound'    => self::directionView( $id, self::DIRECTION_INBOUND ),
			'outbound'   => self::directionView( $id, self::DIRECTION_OUTBOUND ),
		];
	}

	/**
	 * @param array{trip_id?:int,pickup?:string} $inbound
	 * @param array{trip_id?:int} $outbound
	 *
	 * @return array{
	 *   booking_id:int,
	 *   inbound:array{status:string,waitlist_position:?int},
	 *   outbound:array{status:string,waitlist_position:?int},
	 * }
	 *
	 * @throws \RuntimeException if the email is not registered in Elementor,
	 *         or if both directions are empty.
	 */
	public static function create( string $email, array $inbound = [], array $outbound = [] ): array {
		$email = strtolower( trim( $email ) );

		// Fix #2: public booking creation respects the cancellation deadline.
		if ( ! CancellationPolicy::isOpen() ) {
			throw new \RuntimeException( 'Bookings are closed for this event.' );
		}

		$inboundTrip  = isset( $inbound['trip_id'] )  ? (int) $inbound['trip_id']  : 0;
		$outboundTrip = isset( $outbound['trip_id'] ) ? (int) $outbound['trip_id'] : 0;

		if ( $inboundTrip <= 0 && $outboundTrip <= 0 ) {
			throw new \InvalidArgumentException( 'Pick at least one direction.' );
		}

		self::assertTripIs( $inboundTrip,  'OPO-IN' );
		self::assertTripIs( $outboundTrip, 'OPO-OUT' );

		// Fix #3: whitelist inbound pickup before persisting it.
		$inboundPickup = isset( $inbound['pickup'] ) ? self::normalizePickup( (string) $inbound['pickup'] ) : null;
		if ( $inboundTrip > 0 && $inboundPickup === null ) {
			throw new \InvalidArgumentException( 'A valid inbound pickup location is required.' );
		}

		$profile = ElementorLookup::findByEmail( $email );
		if ( ! $profile ) {
			throw new \RuntimeException( 'Email is not registered for the event.' );
		}

		$bookingId = self::findBookingId( $email );
		if ( ! $bookingId ) {
			$bookingId = self::createBookingPost( $email, $profile );
		}

		// Inbound
		$inboundResult = [ 'status' => 'none', 'waitlist_position' => null ];
		if ( $inboundTrip > 0 ) {
			// Fix #1: if the participant is switching trips on this direction,
			// release the old ledger row first so we never leak capacity.
			self::releaseStale( $bookingId, self::DIRECTION_INBOUND, $inboundTrip );

			$inboundResult = self::claimOrWaitlist( $bookingId, $inboundTrip, self::DIRECTION_INBOUND );
			update_post_meta( $bookingId, 'inbound_trip_id', $inboundTrip );
			update_post_meta( $bookingId, 'inbound_status',  $inboundResult['status'] );
			if ( $inboundPickup !== null ) {
				update_post_meta( $bookingId, 'inbound_pickup_location', $inboundPickup );
			}
		}

		// Outbound
		$outboundResult = [ 'status' => 'none', 'waitlist_position' => null ];
		if ( $outboundTrip > 0 ) {
			self::releaseStale( $bookingId, self::DIRECTION_OUTBOUND, $outboundTrip );

			$outboundResult = self::claimOrWaitlist( $bookingId, $outboundTrip, self::DIRECTION_OUTBOUND );
			update_post_meta( $bookingId, 'outbound_trip_id', $outboundTrip );
			update_post_meta( $bookingId, 'outbound_status',  $outboundResult['status'] );
		}

		update_post_meta( $bookingId, 'gdpr_accepted_at', \NVF\BusBooking\Support\Time::nowMysql() );
		update_post_meta( $bookingId, 'source', 'public' );
		self::appendHistory( $bookingId, $email, 'book', sprintf(
			'inbound=%s outbound=%s',
			$inboundResult['status'],
			$outboundResult['status']
		) );

		Logger::info( 'booking.created', [
			'booking_id'   => $bookingId,
			'email'        => self::mask( $email ),
			'inbound_status'  => $inboundResult['status'],
			'outbound_status' => $outboundResult['status'],
		] );

		self::notify( $bookingId, $email, 'booking.created' );

		return [
			'booking_id' => $bookingId,
			'inbound'    => $inboundResult,
			'outbound'   => $outboundResult,
		];
	}

	/**
	 * Admin-bypass create: skips the Elementor lookup and (optionally) the
	 * capacity guard. Used by the "Add booking" admin page (§7.3).
	 *
	 * @param array{trip_id?:int,pickup?:string} $inbound
	 * @param array{trip_id?:int} $outbound
	 */
	public static function createAsAdmin( string $email, string $name, string $phone, array $inbound = [], array $outbound = [], bool $overrideCapacity = false ): array {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			throw new \InvalidArgumentException( 'Valid email required.' );
		}

		$inboundTrip  = isset( $inbound['trip_id'] )  ? (int) $inbound['trip_id']  : 0;
		$outboundTrip = isset( $outbound['trip_id'] ) ? (int) $outbound['trip_id'] : 0;
		if ( $inboundTrip <= 0 && $outboundTrip <= 0 ) {
			throw new \InvalidArgumentException( 'Pick at least one direction.' );
		}
		self::assertTripIs( $inboundTrip,  'OPO-IN' );
		self::assertTripIs( $outboundTrip, 'OPO-OUT' );

		$bookingId = self::findBookingId( $email );
		if ( ! $bookingId ) {
			$bookingId = self::createBookingPost( $email, [ 'name' => $name, 'phone' => $phone ] );
		} else {
			if ( $name !== '' )  update_post_meta( $bookingId, 'participant_name',  $name );
			if ( $phone !== '' ) update_post_meta( $bookingId, 'participant_phone', $phone );
		}

		// Same pickup validation rules apply on the admin path.
		$inboundPickup = isset( $inbound['pickup'] ) ? self::normalizePickup( (string) $inbound['pickup'] ) : null;

		$inboundResult = [ 'status' => 'none', 'waitlist_position' => null ];
		if ( $inboundTrip > 0 ) {
			self::releaseStale( $bookingId, self::DIRECTION_INBOUND, $inboundTrip );

			$inboundResult = $overrideCapacity
				? self::forceClaim( $bookingId, $inboundTrip, self::DIRECTION_INBOUND )
				: self::claimOrWaitlist( $bookingId, $inboundTrip, self::DIRECTION_INBOUND );
			update_post_meta( $bookingId, 'inbound_trip_id', $inboundTrip );
			update_post_meta( $bookingId, 'inbound_status',  $inboundResult['status'] );
			if ( $inboundPickup !== null ) {
				update_post_meta( $bookingId, 'inbound_pickup_location', $inboundPickup );
			}
		}

		$outboundResult = [ 'status' => 'none', 'waitlist_position' => null ];
		if ( $outboundTrip > 0 ) {
			self::releaseStale( $bookingId, self::DIRECTION_OUTBOUND, $outboundTrip );

			$outboundResult = $overrideCapacity
				? self::forceClaim( $bookingId, $outboundTrip, self::DIRECTION_OUTBOUND )
				: self::claimOrWaitlist( $bookingId, $outboundTrip, self::DIRECTION_OUTBOUND );
			update_post_meta( $bookingId, 'outbound_trip_id', $outboundTrip );
			update_post_meta( $bookingId, 'outbound_status',  $outboundResult['status'] );
		}

		update_post_meta( $bookingId, 'source', 'admin' );
		// gdpr_accepted_at intentionally NOT set — admin path, no consent collected.

		self::appendHistory( $bookingId, 'admin#' . get_current_user_id(), 'admin_add', sprintf(
			'override=%s inbound=%s outbound=%s',
			$overrideCapacity ? '1' : '0',
			$inboundResult['status'],
			$outboundResult['status']
		) );

		Logger::info( 'booking.admin_added', [
			'booking_id' => $bookingId,
			'email'      => self::mask( $email ),
			'override'   => $overrideCapacity,
			'by_user'    => get_current_user_id(),
		] );

		self::notify( $bookingId, $email, 'booking.created' );

		return [ 'booking_id' => $bookingId, 'inbound' => $inboundResult, 'outbound' => $outboundResult ];
	}

	/**
	 * Bypass the capacity guard. Writes the ledger row regardless of count —
	 * used only by admin-add when overrideCapacity is enabled. Logs at WARNING
	 * so the operator can audit later.
	 */
	private static function forceClaim( int $bookingId, int $tripId, string $direction ): array {
		global $wpdb;
		$table = $wpdb->prefix . SeatLedger::TABLE_SUFFIX;
		$wpdb->insert( $table, [
			'trip_id'    => $tripId,
			'booking_id' => $bookingId,
			'direction'  => $direction,
			'created_at' => Time::nowMysql(),
		], [ '%d', '%d', '%s', '%s' ] );
		Logger::warning( 'seat.force_claim', [ 'trip_id' => $tripId, 'booking_id' => $bookingId ] );
		return [ 'status' => 'confirmed', 'waitlist_position' => null ];
	}

	public static function updatePickup( string $email, string $pickup ): void {
		if ( ! CancellationPolicy::isOpen() ) {
			throw new \RuntimeException( 'Edits are closed for this event.' );
		}
		// Fix #3: whitelist before persisting.
		$normalized = self::normalizePickup( $pickup );
		if ( $normalized === null ) {
			throw new \InvalidArgumentException( 'Invalid pickup location.' );
		}
		$id = self::findBookingId( $email );
		if ( ! $id ) {
			throw new \RuntimeException( 'No booking found for this email.' );
		}
		update_post_meta( $id, 'inbound_pickup_location', $normalized );
		self::appendHistory( $id, $email, 'edit_pickup', $normalized );
		Logger::info( 'booking.pickup_updated', [ 'booking_id' => $id, 'pickup' => $normalized ] );
	}

	public static function cancel( string $email, string $direction ): array {
		return self::doCancel( $email, $direction, false );
	}

	/** Admin-path cancel: bypasses the cancellation deadline. */
	public static function cancelAsAdmin( string $email, string $direction ): array {
		return self::doCancel( $email, $direction, true );
	}

	private static function doCancel( string $email, string $direction, bool $byAdmin ): array {
		if ( ! $byAdmin && ! CancellationPolicy::isOpen() ) {
			throw new \RuntimeException( 'Cancellations are closed for this event.' );
		}
		$id = self::findBookingId( $email );
		if ( ! $id ) {
			throw new \RuntimeException( 'No booking found for this email.' );
		}
		if ( ! in_array( $direction, [ 'inbound', 'outbound', 'both' ], true ) ) {
			throw new \InvalidArgumentException( 'Direction must be inbound, outbound, or both.' );
		}

		$directions = $direction === 'both' ? [ self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND ] : [ $direction ];

		// Capture which legs were previously confirmed AND on which trip so that
		// once we cancel, we can poke the waitlist for those trips.
		$freedSeats = [];
		foreach ( $directions as $d ) {
			$statusKey = $d === self::DIRECTION_INBOUND ? 'inbound_status'  : 'outbound_status';
			$tripKey   = $d === self::DIRECTION_INBOUND ? 'inbound_trip_id' : 'outbound_trip_id';
			if ( (string) get_post_meta( $id, $statusKey, true ) === 'confirmed' ) {
				$prevTrip = (int) get_post_meta( $id, $tripKey, true );
				if ( $prevTrip > 0 ) {
					$freedSeats[] = [ 'trip_id' => $prevTrip, 'direction' => $d ];
				}
			}
			self::cancelDirection( $id, $d );
		}
		$actor = $byAdmin ? 'admin#' . ( get_current_user_id() ?: 0 ) : $email;
		self::appendHistory( $id, $actor, 'cancel', implode( ',', $directions ) );
		Logger::info( 'booking.cancelled', [
			'booking_id' => $id,
			'directions' => $directions,
			'by_admin'   => $byAdmin,
		] );

		self::notify( $id, $email, 'booking.cancelled' );

		// Each freed seat triggers the simultaneous-notification flow on its
		// trip+direction. WaitlistService::notifyAll is idempotent per-seat so
		// it's safe to call even if no waitlisters exist.
		foreach ( $freedSeats as $seat ) {
			try {
				WaitlistService::notifyAll( $seat['trip_id'], $seat['direction'] );
			} catch ( \Throwable $e ) {
				Logger::error( 'waitlist.notify_failed', [
					'trip_id'   => $seat['trip_id'],
					'direction' => $seat['direction'],
					'reason'    => $e->getMessage(),
				] );
			}
		}

		return self::snapshot( $email ) ?? [ 'booking_id' => $id ];
	}

	private static function notify( int $bookingId, string $email, string $event ): void {
		try {
			$context = BookingContext::build( $bookingId );
		} catch ( \Throwable $e ) {
			Logger::error( 'mail.context_failed', [ 'booking_id' => $bookingId, 'reason' => $e->getMessage() ] );
			return;
		}

		try {
			if ( $event === 'booking.created' ) {
				Mailer::sendConfirmation( $email, $context );
			} elseif ( $event === 'booking.cancelled' ) {
				Mailer::sendCancellation( $email, $context );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'mail.participant_failed', [ 'booking_id' => $bookingId, 'event' => $event, 'reason' => $e->getMessage() ] );
		}

		try {
			Mailer::sendAdminNotification( $event, $context );
		} catch ( \Throwable $e ) {
			Logger::error( 'mail.admin_failed', [ 'booking_id' => $bookingId, 'event' => $event, 'reason' => $e->getMessage() ] );
		}

		try {
			GoogleSheetsWebhook::dispatch( $event, $bookingId );
		} catch ( \Throwable $e ) {
			Logger::error( 'sheets.dispatch_failed', [ 'booking_id' => $bookingId, 'event' => $event, 'reason' => $e->getMessage() ] );
		}
	}

	// ------------------------------------------------------------------------

	/**
	 * Drop any existing ledger row for ($bookingId, $direction) IF the
	 * participant is moving to a different trip. Idempotent.
	 */
	private static function releaseStale( int $bookingId, string $direction, int $newTripId ): void {
		$tripKey  = $direction === self::DIRECTION_INBOUND ? 'inbound_trip_id' : 'outbound_trip_id';
		$prevTrip = (int) get_post_meta( $bookingId, $tripKey, true );
		if ( $prevTrip > 0 && $prevTrip !== $newTripId ) {
			SeatLedger::release( $prevTrip, $bookingId );
			Logger::info( 'seat.released_on_swap', [
				'booking_id'   => $bookingId,
				'direction'    => $direction,
				'previous_trip'=> $prevTrip,
				'new_trip'     => $newTripId,
			] );
		}
	}

	/** Returns the canonical pickup key, or null if the value is unknown. */
	private static function normalizePickup( string $raw ): ?string {
		$candidate = sanitize_key( $raw );
		return array_key_exists( $candidate, MetaBoxes::PICKUP_LOCATIONS ) ? $candidate : null;
	}

	private static function cancelDirection( int $bookingId, string $direction ): void {
		$tripKey   = $direction === self::DIRECTION_INBOUND ? 'inbound_trip_id'      : 'outbound_trip_id';
		$statusKey = $direction === self::DIRECTION_INBOUND ? 'inbound_status'       : 'outbound_status';
		$tripId    = (int) get_post_meta( $bookingId, $tripKey, true );
		if ( $tripId > 0 ) {
			SeatLedger::release( $tripId, $bookingId );
		}
		update_post_meta( $bookingId, $statusKey, 'cancelled' );
	}

	private static function claimOrWaitlist( int $bookingId, int $tripId, string $direction ): array {
		$status = SeatLedger::claim( $tripId, $bookingId, $direction );
		if ( $status === 'waitlist' ) {
			return [ 'status' => 'waitlist', 'waitlist_position' => self::nextWaitlistPosition( $tripId, $direction ) ];
		}
		return [ 'status' => 'confirmed', 'waitlist_position' => null ];
	}

	private static function nextWaitlistPosition( int $tripId, string $direction ): int {
		$statusKey = $direction === self::DIRECTION_INBOUND ? 'inbound_status'  : 'outbound_status';
		$tripKey   = $direction === self::DIRECTION_INBOUND ? 'inbound_trip_id' : 'outbound_trip_id';
		$existing  = ( new \WP_Query( [
			'post_type'      => PostTypes::BOOKING,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => $tripKey,   'value' => $tripId,   'compare' => '=' ],
				[ 'key' => $statusKey, 'value' => 'waitlist', 'compare' => '=' ],
			],
		] ) )->posts;
		return count( $existing ) + 1;
	}

	private static function directionView( int $bookingId, string $direction ): array {
		$tripKey   = $direction === self::DIRECTION_INBOUND ? 'inbound_trip_id' : 'outbound_trip_id';
		$statusKey = $direction === self::DIRECTION_INBOUND ? 'inbound_status'  : 'outbound_status';
		$wlKey     = $direction === self::DIRECTION_INBOUND ? 'inbound_waitlist_position' : 'outbound_waitlist_position';

		return [
			'status'            => (string) ( get_post_meta( $bookingId, $statusKey, true ) ?: 'none' ),
			'trip_id'           => ( $t = (int) get_post_meta( $bookingId, $tripKey, true ) ) > 0 ? $t : null,
			'pickup'            => $direction === self::DIRECTION_INBOUND
				? ( ( $p = (string) get_post_meta( $bookingId, 'inbound_pickup_location', true ) ) !== '' ? $p : null )
				: null,
			'waitlist_position' => ( $w = (int) get_post_meta( $bookingId, $wlKey, true ) ) > 0 ? $w : null,
		];
	}

	private static function findBookingId( string $email ): ?int {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			return null;
		}
		$table = $wpdb->prefix . EmailUniqueness::TABLE_SUFFIX;
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT booking_id FROM {$table} WHERE email = %s", $email ) );
		return $id ? (int) $id : null;
	}

	private static function createBookingPost( string $email, array $profile ): int {
		$title = ( $profile['name'] ?? '' ) !== '' ? $profile['name'] : $email;
		$id = wp_insert_post( [
			'post_type'   => PostTypes::BOOKING,
			'post_status' => 'publish',
			'post_title'  => $title,
		], true );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'Failed to create booking: ' . $id->get_error_message() );
		}
		update_post_meta( $id, 'participant_email', $email );
		update_post_meta( $id, 'participant_name',  $profile['name']  ?? '' );
		update_post_meta( $id, 'participant_phone', $profile['phone'] ?? '' );
		return (int) $id;
	}

	private static function assertTripIs( int $tripId, string $expectedDirection ): void {
		if ( $tripId <= 0 ) {
			return;
		}
		if ( get_post_type( $tripId ) !== PostTypes::TRIP ) {
			throw new \InvalidArgumentException( 'Unknown trip.' );
		}
		$direction = (string) get_post_meta( $tripId, 'direction', true );
		if ( $direction !== $expectedDirection ) {
			throw new \InvalidArgumentException( sprintf( 'Trip %d is %s, expected %s.', $tripId, $direction, $expectedDirection ) );
		}
		$status = (string) get_post_meta( $tripId, 'status', true );
		if ( $status === 'cancelled' ) {
			throw new \RuntimeException( 'Trip has been cancelled.' );
		}
	}

	private static function appendHistory( int $bookingId, string $actor, string $action, string $note ): void {
		$existing   = get_post_meta( $bookingId, 'history', true );
		$existing   = is_array( $existing ) ? $existing : [];
		$existing[] = [
			'timestamp' => Time::nowMysql(),
			'actor'     => $actor,
			'action'    => $action,
			'note'      => $note,
		];
		update_post_meta( $bookingId, 'history', $existing );
	}

	private static function mask( string $email ): string {
		$at = strrpos( $email, '@' );
		return $at !== false && $at >= 2
			? substr( $email, 0, 1 ) . '***' . substr( $email, $at )
			: '***';
	}
}
