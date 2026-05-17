<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Rest;

use NVF\BusBooking\Support\DebugContext;
use NVF\BusBooking\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Temporary debug surface — feeds the floating panel on the public booking page
 * and the admin Debug Log tab.
 *
 * Access rules:
 * - Admins (manage_options) can always read /debug.
 * - Anonymous visitors get access only when they pass ?nvf_debug=1 along with
 *   a matching debug nonce (printed in the page by PublicAssets when admins view it).
 *
 * The debug nonce is bound to the wp_session_token to keep curiosity scans off.
 */
final class DebugController {

	public const NAMESPACE  = 'nvf/v1';
	public const NONCE_NAME = 'nvf_debug';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( self::NAMESPACE, '/debug', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'snapshot' ],
			'permission_callback' => [ self::class, 'canRead' ],
			'args'                => [
				'lines' => [
					'type'              => 'integer',
					'default'           => 50,
					'minimum'           => 1,
					'maximum'           => 500,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/debug/clear', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'clear' ],
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		] );
	}

	public static function canRead( WP_REST_Request $request ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		// Anonymous opt-in path: ?nvf_debug=<nonce>.
		$nonce = (string) $request->get_param( 'nvf_debug' );
		return $nonce !== '' && wp_verify_nonce( $nonce, self::NONCE_NAME ) !== false;
	}

	public static function snapshot( WP_REST_Request $request ): WP_REST_Response {
		$lines = (int) $request->get_param( 'lines' );
		return new WP_REST_Response( DebugContext::snapshot( $lines ) );
	}

	public static function clear(): WP_REST_Response {
		$ok = Logger::clear();
		return new WP_REST_Response( [ 'ok' => $ok ] );
	}
}
