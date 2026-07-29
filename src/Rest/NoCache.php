<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Forces no-store caching on every response in the plugin's REST namespace.
 *
 * Participants authenticate with the plugin's own `nvf_session` cookie, NOT a
 * WordPress login cookie. Full-page / CDN caches (WP Rocket, LiteSpeed,
 * Cloudflare "Cache Everything", host object+page caches) only bypass their
 * cache when they detect `wordpress_logged_in_*` cookies, so to them every
 * participant looks anonymous — which means per-participant endpoints like
 * `/my-booking` get cached and replayed. Symptoms: booking state looks stale
 * after a cancel (the leg still shows active, "cancel again", no re-book), and
 * — worse — one participant's booking can be served to another.
 *
 * None of the plugin's endpoints are ever cacheable (booking snapshots are
 * per-session; `/trips` availability changes on every booking), so we stamp
 * explicit no-store headers on the response for the whole namespace. A well-
 * behaved cache honours these; Cloudflare "Cache Everything" still has to be
 * told to respect origin headers, so this is defence-in-depth alongside the
 * host-level rule that excludes `/wp-json/nvf/v1/*` from caching.
 */
final class NoCache {

	/** Route prefix (with leading slash) this filter applies to. */
	private const ROUTE_PREFIX = '/nvf/v1/';

	public static function register(): void {
		add_filter( 'rest_post_dispatch', [ self::class, 'stamp' ], 10, 3 );
	}

	/**
	 * @param mixed            $result  Dispatch result (usually a WP_REST_Response).
	 * @param mixed            $server  The REST server (unused).
	 * @param WP_REST_Request  $request The request being served.
	 *
	 * @return mixed The response, with no-store headers added when in namespace.
	 */
	public static function stamp( $result, $server, WP_REST_Request $request ) {
		if ( ! $result instanceof WP_REST_Response ) {
			return $result;
		}
		if ( ! str_starts_with( (string) $request->get_route(), self::ROUTE_PREFIX ) ) {
			return $result;
		}

		$result->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$result->header( 'Pragma', 'no-cache' );
		$result->header( 'Expires', '0' );
		// Nudge intermediaries that DO key on cookies to at least vary per session.
		$result->header( 'Vary', 'Cookie', false );
		// This host runs LiteSpeed (Hostinger/LSCache), whose "Cache REST API"
		// option caches GET /wp-json responses regardless of the Cache-Control
		// above — that decision is governed by the plugin's PHP API, not by
		// response headers. Fire LiteSpeed's documented no-cache control so
		// LSCache skips these per-session routes whatever the admin toggle says.
		// Both are no-ops when LiteSpeed isn't installed; harmless elsewhere.
		$result->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		do_action( 'litespeed_control_set_nocache', 'nvf per-session REST response' );

		return $result;
	}
}
