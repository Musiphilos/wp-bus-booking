<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Booking;

use NVF\BusBooking\Auth\SessionCookie;
use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints for the booking flow:
 *
 *   GET    /wp-json/nvf/v1/trips         — public list of trips with availability
 *   GET    /wp-json/nvf/v1/my-booking    — current participant's booking snapshot
 *   POST   /wp-json/nvf/v1/book          — create/replace booking (atomic)
 *   PATCH  /wp-json/nvf/v1/my-booking    — edit pickup location
 *   DELETE /wp-json/nvf/v1/my-booking    — cancel one or both directions
 *
 * Identity comes from the nvf_session cookie (M2). No WP user account needed.
 */
final class BookingController {

	public const NAMESPACE = 'nvf/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( self::NAMESPACE, '/trips', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'listTrips' ],
		] );

		register_rest_route( self::NAMESPACE, '/my-booking', [
			[
				'methods'             => 'GET',
				'permission_callback' => [ self::class, 'requireSession' ],
				'callback'            => [ self::class, 'getMine' ],
			],
			[
				'methods'             => 'PATCH',
				'permission_callback' => [ self::class, 'requireSession' ],
				'callback'            => [ self::class, 'patchMine' ],
				'args'                => [
					'inbound_pickup' => [ 'type' => 'string' ],
				],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => [ self::class, 'requireSession' ],
				'callback'            => [ self::class, 'deleteMine' ],
				'args'                => [
					'direction' => [ 'type' => 'string', 'default' => 'both', 'enum' => [ 'inbound', 'outbound', 'both' ] ],
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/book', [
			'methods'             => 'POST',
			'permission_callback' => [ self::class, 'requireSession' ],
			'callback'            => [ self::class, 'book' ],
		] );
	}

	public static function requireSession(): bool {
		return SessionCookie::read() !== null;
	}

	public static function listTrips(): WP_REST_Response {
		$query = new \WP_Query( [
			'post_type'      => PostTypes::TRIP,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'no_found_rows'  => true,
			'orderby'        => 'meta_value',
			'meta_key'       => 'departure_datetime',
			'order'          => 'ASC',
		] );
		$out = [];
		foreach ( $query->posts as $post ) {
			$direction = (string) get_post_meta( $post->ID, 'direction', true );
			$capacity  = (int) ( get_post_meta( $post->ID, 'capacity', true ) ?: 0 );
			$confirmed = SeatLedger::countConfirmed( $post->ID );
			$status    = (string) get_post_meta( $post->ID, 'status', true );
			$out[]     = [
				'id'         => $post->ID,
				'code'       => (string) get_post_meta( $post->ID, 'trip_code', true ),
				'direction'  => $direction,
				'title'      => $post->post_title,
				'departure'  => self::formatLisbon( (string) get_post_meta( $post->ID, 'departure_datetime', true ) ),
				'stops'      => self::stopsView( (array) get_post_meta( $post->ID, 'stops', true ) ),
				'capacity'   => $capacity,
				'confirmed'  => $confirmed,
				'available'  => max( 0, $capacity - $confirmed ),
				'status'     => $status ?: 'open',
			];
		}
		wp_reset_postdata();
		return new WP_REST_Response( [ 'trips' => $out ] );
	}

	public static function getMine(): WP_REST_Response {
		$session = SessionCookie::read();
		$snap    = BookingService::snapshot( $session['email'] );
		return new WP_REST_Response( [
			'authenticated' => true,
			'email'         => $session['email'],
			'booking'       => $snap,
		] );
	}

	public static function book( WP_REST_Request $request ): WP_REST_Response {
		$session = SessionCookie::read();
		$email   = $session['email'];

		$inboundTrip   = (int) ( $request->get_param( 'inbound_trip_id' )  ?? 0 );
		$outboundTrip  = (int) ( $request->get_param( 'outbound_trip_id' ) ?? 0 );
		$inboundPickup = (string) ( $request->get_param( 'inbound_pickup' ) ?? '' );
		$gdpr          = (bool) ( $request->get_param( 'gdpr' ) ?? false );

		if ( ! $gdpr ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'gdpr_required' ], 400 );
		}
		if ( $inboundTrip <= 0 && $outboundTrip <= 0 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'no_trip_selected' ], 400 );
		}
		if ( $inboundTrip > 0 && $inboundPickup === '' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'pickup_required' ], 400 );
		}

		try {
			$result = BookingService::create(
				$email,
				$inboundTrip  > 0 ? [ 'trip_id' => $inboundTrip, 'pickup' => $inboundPickup ] : [],
				$outboundTrip > 0 ? [ 'trip_id' => $outboundTrip ] : []
			);
		} catch ( \Throwable $e ) {
			Logger::warning( 'booking.create_failed', [ 'email' => self::mask( $email ), 'reason' => $e->getMessage() ] );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'booking_failed', 'message' => $e->getMessage() ], 422 );
		}

		// Spec §4.3 partial availability: if any direction landed on the waitlist,
		// surface the per-direction status so the UI can show the confirmation step.
		$anyWaitlist = ( $result['inbound']['status'] ?? '' ) === 'waitlist'
		            || ( $result['outbound']['status'] ?? '' ) === 'waitlist';

		return new WP_REST_Response( [
			'ok'      => true,
			'partial' => $anyWaitlist,
			'result'  => $result,
		] );
	}

	public static function patchMine( WP_REST_Request $request ): WP_REST_Response {
		$session = SessionCookie::read();
		$pickup  = (string) ( $request->get_param( 'inbound_pickup' ) ?? '' );
		if ( $pickup === '' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'pickup_required' ], 400 );
		}
		try {
			BookingService::updatePickup( $session['email'], $pickup );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'pickup_invalid', 'message' => $e->getMessage() ], 400 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'patch_failed', 'message' => $e->getMessage() ], 422 );
		}
		return new WP_REST_Response( [ 'ok' => true, 'booking' => BookingService::snapshot( $session['email'] ) ] );
	}

	public static function deleteMine( WP_REST_Request $request ): WP_REST_Response {
		$session   = SessionCookie::read();
		$direction = (string) ( $request->get_param( 'direction' ) ?? 'both' );
		try {
			BookingService::cancel( $session['email'], $direction );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'cancel_failed', 'message' => $e->getMessage() ], 422 );
		}
		return new WP_REST_Response( [ 'ok' => true, 'booking' => BookingService::snapshot( $session['email'] ) ] );
	}

	// ------------------------------------------------------------------------

	private static function stopsView( array $raw ): array {
		$out = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = [
				'label' => (string) ( $row['label'] ?? '' ),
				'time'  => (string) ( $row['time']  ?? '' ),
			];
		}
		return $out;
	}

	private static function formatLisbon( string $dt ): string {
		if ( $dt === '' ) {
			return '';
		}
		$formatted = \NVF\BusBooking\Support\Time::formatHuman( $dt );
		return $formatted !== '' ? $formatted : $dt;
	}

	private static function mask( string $email ): string {
		$at = strrpos( $email, '@' );
		return $at !== false && $at >= 2
			? substr( $email, 0, 1 ) . '***' . substr( $email, $at )
			: '***';
	}
}
