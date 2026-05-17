<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Support;

use NVF\BusBooking\Domain\StringsRegistry;

/**
 * Resolves an editable string by id, applies token substitution, and returns
 * the final rendered value. Sister of StringsRegistry — the registry declares
 * the schema, this class does the work at call time.
 *
 * Mental model:
 *   render('booking.h1')                              → 'Manage your shuttle seat'
 *   render('email.confirmation.greeting', $ctx)       → 'Hi John — your booking BUS-12345 is on file. Here are the details:'
 *
 * Escaping contract:
 *   - For `type=text` and `type=textarea` entries the renderer returns the raw
 *     concatenated string. Caller is responsible for esc_html() / esc_attr() etc.
 *   - For `type=html` entries, token VALUES are HTML-escaped at substitution
 *     time so dynamic data can't smuggle markup, but the surrounding admin-
 *     authored markup is emitted as-is. Caller echoes directly.
 *
 * Storage:
 *   Stored as a single serialized array under WP option `nvf_strings`.
 *   `Settings::get('nvf_strings', [])` honours the same MB-bag fallback layer
 *   used everywhere else, so admin edits via the (coming) Text & Copy page
 *   land in the canonical bag.
 */
final class StringRenderer {

	public const OPTION_KEY = 'nvf_strings';

	/** Per-request memo so we don't spam debug.log on every render of the same broken id. */
	private static array $warned = [];

	/**
	 * @param array<string,scalar|null> $context token values keyed by name
	 */
	public static function render( string $id, array $context = [] ): string {
		$entry = StringsRegistry::find( $id );
		if ( ! $entry ) {
			self::warnOnce( 'strings.unknown_id:' . $id, 'strings.unknown_id', [ 'id' => $id ] );
			return '';
		}

		$stored = self::storedValue( $id );

		// `blank_hides` entries treat explicit empty as a hide sentinel — return
		// nothing rather than falling back to the registry default.
		if ( $stored === '' && ! empty( $entry['blank_hides'] ) ) {
			return '';
		}
		$value = ( $stored !== null && $stored !== '' ) ? $stored : (string) $entry['default'];
		if ( $value === '' ) {
			return '';
		}

		return self::interpolate( $value, $entry, $context );
	}

	/**
	 * Plain-text variant of render(). Same registry key, but any markup the
	 * admin or registry default may have included is stripped, and whitespace
	 * is normalised so the result reads cleanly as plain prose. Used by the
	 * `.txt.php` email templates so a single registry entry feeds both formats.
	 *
	 * @param array<string,scalar|null> $context
	 */
	public static function renderPlain( string $id, array $context = [] ): string {
		$rendered = self::render( $id, $context );
		if ( $rendered === '' ) {
			return '';
		}
		$stripped = wp_strip_all_tags( $rendered, false );
		// Collapse runs of whitespace introduced by removed inline tags but
		// preserve intentional newlines in textarea-stored copy.
		$lines = preg_split( '/\R/', $stripped ) ?: [];
		$lines = array_map( static fn( $l ) => trim( preg_replace( '/\s+/', ' ', $l ) ?? '' ), $lines );
		return implode( "\n", $lines );
	}

	/** Returns the canonical default for an id without consulting the bag. */
	public static function defaultFor( string $id ): string {
		$entry = StringsRegistry::find( $id );
		return $entry ? (string) $entry['default'] : '';
	}

	/** Returns the current admin override (or null if none). */
	public static function storedValue( string $id ): ?string {
		$bag = Settings::get( self::OPTION_KEY, [] );
		if ( ! is_array( $bag ) ) {
			return null;
		}
		if ( ! array_key_exists( $id, $bag ) ) {
			return null;
		}
		$v = $bag[ $id ];
		if ( $v === null ) {
			return null;
		}
		return (string) $v;
	}

	/**
	 * Test-only / settings-page write path.
	 *
	 *   $value = null  → remove the override (renderer falls back to default).
	 *   $value = ''    → for `blank_hides` entries, store the empty sentinel
	 *                    so the renderer returns nothing. For other entries,
	 *                    same as null (remove).
	 *   $value = '…'   → store the override.
	 */
	public static function setStoredValue( string $id, ?string $value ): void {
		$bag = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $bag ) ) {
			$bag = [];
		}
		if ( $value === null ) {
			unset( $bag[ $id ] );
		} elseif ( $value === '' ) {
			$entry = StringsRegistry::find( $id );
			if ( $entry && ! empty( $entry['blank_hides'] ) ) {
				$bag[ $id ] = '';
			} else {
				unset( $bag[ $id ] );
			}
		} else {
			$bag[ $id ] = $value;
		}
		update_option( self::OPTION_KEY, $bag, true );
	}

	private static function warnOnce( string $memoKey, string $event, array $context ): void {
		if ( isset( self::$warned[ $memoKey ] ) ) {
			return;
		}
		self::$warned[ $memoKey ] = true;
		Logger::warning( $event, $context );
	}

	/**
	 * @param array{tokens:array<int,string>,type:string,id:string} $entry
	 * @param array<string,scalar|null> $context
	 */
	private static function interpolate( string $value, array $entry, array $context ): string {
		$allowed = array_flip( $entry['tokens'] );
		$id      = $entry['id'];
		$isHtml  = ( $entry['type'] === 'html' );

		return (string) preg_replace_callback(
			'/{{\s*(\w+)\s*}}/u',
			static function ( array $m ) use ( $allowed, $context, $id, $isHtml ) {
				$token = $m[1];
				if ( ! isset( $allowed[ $token ] ) ) {
					self::warnOnce( "strings.unknown_token:$id:$token", 'strings.unknown_token', [ 'id' => $id, 'token' => $token ] );
					return $m[0]; // keep literal so admin sees + fixes
				}
				if ( ! array_key_exists( $token, $context ) || $context[ $token ] === null ) {
					return '';
				}
				$raw = (string) $context[ $token ];
				return $isHtml ? esc_html( $raw ) : $raw;
			},
			$value
		);
	}
}
