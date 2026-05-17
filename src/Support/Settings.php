<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Support;

/**
 * Read accessor for the plugin's settings.
 *
 * Meta Box Settings Page stores all fields as a single serialized array
 * under the page id (`nvf_settings`). On top of that we honour any
 * top-level `nvf_*` option that may have been set directly (manual edits,
 * activation defaults, automated tests) — those win, so explicit overrides
 * always beat the UI value.
 *
 * Usage:
 *   Settings::get( 'nvf_event_start_date' );
 *   Settings::get( 'nvf_magic_link_expiry_hours', 24 );
 */
final class Settings {

	private const PAGE_OPTION = 'nvf_settings';

	/**
	 * Address shown to participants on the booking page and inside every
	 * transactional email. Falls back to the sender address — and finally to
	 * `lbswing.com@gmail.com` — so something useful is always rendered.
	 */
	public static function contactEmail(): string {
		$pub = (string) self::get( 'nvf_public_contact_email', '' );
		if ( $pub !== '' ) return $pub;
		$send = (string) self::get( 'nvf_email_sender_address', '' );
		if ( $send !== '' ) return $send;
		return 'lbswing.com@gmail.com';
	}

	public static function get( string $key, $default = null ) {
		$top = get_option( $key, '__nvf_unset__' );
		if ( $top !== '__nvf_unset__' && $top !== '' && $top !== false ) {
			return $top;
		}
		$bag = get_option( self::PAGE_OPTION, [] );
		if ( is_array( $bag ) && array_key_exists( $key, $bag ) ) {
			$value = $bag[ $key ];
			if ( $value !== '' && $value !== null ) {
				return $value;
			}
		}
		return $default;
	}
}
