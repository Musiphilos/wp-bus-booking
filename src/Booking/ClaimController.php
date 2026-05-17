<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Booking;

use NVF\BusBooking\Auth\TokenSigner;
use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Mail\BookingContext;
use NVF\BusBooking\Mail\Mailer;
use NVF\BusBooking\Rest\PublicAssets;
use NVF\BusBooking\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Waitlist "spot opened" claim endpoint (§4.5).
 *
 *   GET /wp-json/nvf/v1/claim?token=…
 *
 * The token encodes (booking_id, trip_id, direction, exp) + HMAC. On first
 * receipt:
 *   1. verify the signature and consume the nonce (single-use).
 *   2. atomically retry SeatLedger::claim — first caller wins.
 *   3. on win → flip booking status to confirmed, fire confirmation email +
 *      admin notification + Sheets webhook, AND notify the other waitlisters
 *      via "spot taken".
 *   4. on lose → redirect to /bus-booking/?nvf_error=spot_taken.
 */
final class ClaimController {

	public const NAMESPACE = 'nvf/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( self::NAMESPACE, '/claim', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'claim' ],
			'args'                => [
				'token' => [ 'type' => 'string', 'required' => true ],
			],
		] );
	}

	public static function claim( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		try {
			$payload = TokenSigner::verify( $token, TokenSigner::KIND_CLAIM );
		} catch ( \Throwable $e ) {
			Logger::warning( 'claim.bad_token', [ 'reason' => $e->getMessage() ] );
			return self::redirect( [ 'nvf_error' => 'invalid_link' ] );
		}

		$nonceKey = 'nvf_claim_used_' . md5( $payload['n'] );
		if ( get_transient( $nonceKey ) ) {
			Logger::warning( 'claim.replay_rejected', [ 'email' => self::mask( $payload['e'] ) ] );
			return self::redirect( [ 'nvf_error' => 'invalid_link' ] );
		}
		set_transient( $nonceKey, 1, max( 60, $payload['exp'] - time() ) );

		$bookingId = (int) ( $payload['b'] ?? 0 );
		$tripId    = (int) ( $payload['t'] ?? 0 );
		$direction = (string) ( $payload['d'] ?? '' );

		if ( $bookingId <= 0 || $tripId <= 0 || ! in_array( $direction, [ 'inbound', 'outbound' ], true ) ) {
			Logger::warning( 'claim.bad_payload', [ 'payload' => $payload ] );
			return self::redirect( [ 'nvf_error' => 'invalid_link' ] );
		}

		if ( get_post_type( $bookingId ) !== PostTypes::BOOKING || get_post_type( $tripId ) !== PostTypes::TRIP ) {
			return self::redirect( [ 'nvf_error' => 'invalid_link' ] );
		}

		$statusKey = $direction === 'inbound' ? 'inbound_status' : 'outbound_status';
		if ( (string) get_post_meta( $bookingId, $statusKey, true ) !== 'waitlist' ) {
			// Already off the waitlist — likely promoted manually or rebooked.
			Logger::info( 'claim.not_waitlist', [ 'booking_id' => $bookingId, 'direction' => $direction ] );
			return self::redirect( [ 'nvf_error' => 'spot_taken' ] );
		}

		// Atomic retry — exact same primitive as the original booking flow.
		$result = SeatLedger::claim( $tripId, $bookingId, $direction );
		if ( $result !== 'confirmed' ) {
			Logger::info( 'claim.lost_race', [ 'booking_id' => $bookingId, 'trip_id' => $tripId ] );
			return self::redirect( [ 'nvf_error' => 'spot_taken' ] );
		}

		update_post_meta( $bookingId, $statusKey, 'confirmed' );
		Logger::info( 'claim.won', [ 'booking_id' => $bookingId, 'trip_id' => $tripId, 'direction' => $direction ] );

		// Mail / sheets — wrapped so a send failure never breaks the flip.
		try {
			$email = (string) get_post_meta( $bookingId, 'participant_email', true );
			$ctx   = BookingContext::build( $bookingId );
			Mailer::sendConfirmation( $email, $ctx );
			Mailer::sendAdminNotification( 'booking.promoted', $ctx );
		} catch ( \Throwable $e ) {
			Logger::error( 'claim.confirmation_mail_failed', [ 'booking_id' => $bookingId, 'reason' => $e->getMessage() ] );
		}

		try {
			\NVF\BusBooking\Integrations\GoogleSheetsWebhook::dispatch( 'booking.promoted', $bookingId );
		} catch ( \Throwable $e ) {
			Logger::error( 'claim.sheets_failed', [ 'booking_id' => $bookingId, 'reason' => $e->getMessage() ] );
		}

		// Tell the rest of the waitlist the seat is gone.
		try {
			WaitlistService::notifyLosers( $tripId, $direction, $bookingId );
		} catch ( \Throwable $e ) {
			Logger::error( 'claim.losers_notify_failed', [ 'trip_id' => $tripId, 'reason' => $e->getMessage() ] );
		}

		return self::redirect( [ 'nvf_claimed' => 1 ] );
	}

	private static function redirect( array $args ): WP_REST_Response {
		$base = PublicAssets::bookingPageUrl() ?: home_url( '/' );
		$resp = new WP_REST_Response( null, 302 );
		$resp->header( 'Location', add_query_arg( $args, $base ) );
		return $resp;
	}

	private static function mask( string $email ): string {
		$at = strrpos( $email, '@' );
		return $at !== false && $at >= 2
			? substr( $email, 0, 1 ) . '***' . substr( $email, $at )
			: '***';
	}
}
