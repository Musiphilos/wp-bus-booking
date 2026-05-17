<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Auth;

use NVF\BusBooking\Rest\PublicAssets;
use NVF\BusBooking\Support\Logger;
use NVF\BusBooking\Support\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints for the magic-link flow.
 *
 *   POST /wp-json/nvf/v1/verify-email   { email }
 *     - Rate-limited (5 / hour / IP).
 *     - If the email exists in Elementor submissions → mails a single-use link.
 *     - Returns the same generic 200 either way to prevent email enumeration.
 *
 *   GET /wp-json/nvf/v1/consume?token=…
 *     - Validates the token, issues the session cookie, redirects to the
 *       booking page on success or to ?nvf_error=… on failure.
 *
 *   POST /wp-json/nvf/v1/logout
 *     - Clears the session cookie. Used by Step 1 "Use a different email".
 */
final class MagicLinkController {

	public const NAMESPACE = 'nvf/v1';

	private const RATE_LIMIT_BUCKET       = 'verify_email';
	private const RATE_LIMIT_LIMIT        = 5;
	private const RATE_LIMIT_WINDOW       = HOUR_IN_SECONDS;
	private const RATE_LIMIT_EMAIL_BUCKET = 'verify_email_per_email';
	private const RATE_LIMIT_EMAIL_LIMIT  = 3;
	private const TOKEN_TTL_FALLBACK      = 24;

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( self::NAMESPACE, '/verify-email', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'verifyEmail' ],
			'args'                => [
				'email' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_email',
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/consume', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'consume' ],
			'args'                => [
				'token' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/logout', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'logout' ],
		] );

		register_rest_route( self::NAMESPACE, '/me', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'me' ],
		] );
	}

	public static function verifyEmail( WP_REST_Request $request ): WP_REST_Response {
		$email = (string) $request->get_param( 'email' );
		$email = strtolower( trim( $email ) );

		if ( $email === '' || ! is_email( $email ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_email' ], 400 );
		}

		// Two buckets so an attacker can't escape either axis: per-IP defeats
		// volume from one source; per-email defeats enumeration via IP rotation.
		$ipRl    = RateLimiter::hit( self::RATE_LIMIT_BUCKET, self::RATE_LIMIT_LIMIT, self::RATE_LIMIT_WINDOW );
		$emailRl = RateLimiter::hit(
			self::RATE_LIMIT_EMAIL_BUCKET,
			self::RATE_LIMIT_EMAIL_LIMIT,
			self::RATE_LIMIT_WINDOW,
			$email
		);

		if ( ! $ipRl['allowed'] || ! $emailRl['allowed'] ) {
			$reason = ! $ipRl['allowed'] ? 'ip' : 'email';
			$retry  = max( $ipRl['retry_after'], $emailRl['retry_after'] );
			Logger::warning( 'magic_link.rate_limited', [
				'email'       => self::mask( $email ),
				'by'          => $reason,
				'retry_after' => $retry,
			] );
			$response = new WP_REST_Response( [ 'ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry ], 429 );
			$response->header( 'Retry-After', (string) $retry );
			return $response;
		}

		$match = ElementorLookup::findByEmail( $email );

		if ( $match ) {
			$ttlHours = (int) \NVF\BusBooking\Support\Settings::get( 'nvf_magic_link_expiry_hours', self::TOKEN_TTL_FALLBACK );
			$token    = TokenSigner::sign( $email, $ttlHours * HOUR_IN_SECONDS, TokenSigner::KIND_LINK );
			$url      = add_query_arg( 'token', $token, rest_url( self::NAMESPACE . '/consume' ) );
			Mailer::sendMagicLink( $email, $url, $ttlHours );
			Logger::info( 'magic_link.issued', [ 'email' => self::mask( $email ) ] );
		} else {
			Logger::info( 'magic_link.unknown_email', [ 'email' => self::mask( $email ) ] );
		}

		// Always 200 with the same payload — no enumeration leak.
		return new WP_REST_Response( [
			'ok'      => true,
			'message' => __( "If that email is registered for the event, we've sent you a sign-in link.", 'nvf-bus-booking' ),
		] );
	}

	public static function consume( WP_REST_Request $request ): \WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		try {
			$payload = TokenSigner::verify( $token, TokenSigner::KIND_LINK );
		} catch ( \Throwable $e ) {
			Logger::warning( 'magic_link.consume_failed', [ 'reason' => $e->getMessage() ] );
			return self::redirectToBooking( [ 'nvf_error' => 'invalid_link' ] );
		}

		// Single-use guard: reject replay of an already-consumed nonce.
		$nonceKey = 'nvf_link_used_' . md5( $payload['n'] );
		if ( get_transient( $nonceKey ) ) {
			Logger::warning( 'magic_link.replay_rejected', [ 'email' => self::mask( $payload['e'] ) ] );
			return self::redirectToBooking( [ 'nvf_error' => 'invalid_link' ] );
		}
		$remaining = max( 60, $payload['exp'] - time() );
		set_transient( $nonceKey, 1, $remaining );

		SessionCookie::issue( $payload['e'] );
		Logger::info( 'magic_link.consumed', [ 'email' => self::mask( $payload['e'] ) ] );

		return self::redirectToBooking( [ 'nvf_signed_in' => 1 ] );
	}

	public static function logout(): WP_REST_Response {
		$session = SessionCookie::read();
		SessionCookie::clear();
		Logger::info( 'magic_link.logout', [ 'email' => $session ? self::mask( $session['email'] ) : '(none)' ] );
		return new WP_REST_Response( [ 'ok' => true ] );
	}

	public static function me(): WP_REST_Response {
		$session = SessionCookie::read();
		if ( ! $session ) {
			return new WP_REST_Response( [ 'authenticated' => false ] );
		}
		$profile = ElementorLookup::findByEmail( $session['email'] );
		return new WP_REST_Response( [
			'authenticated' => true,
			'email'         => $session['email'],
			'name'          => $profile['name']  ?? '',
			'phone'         => $profile['phone'] ?? '',
		] );
	}

	private static function redirectToBooking( array $args ): WP_REST_Response {
		$base = PublicAssets::bookingPageUrl() ?: home_url( '/' );
		$url  = add_query_arg( $args, $base );
		$resp = new WP_REST_Response( null, 302 );
		$resp->header( 'Location', $url );
		return $resp;
	}

	private static function mask( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( $at === false || $at < 2 ) {
			return '***';
		}
		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}
}
