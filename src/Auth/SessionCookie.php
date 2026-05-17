<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Auth;

/**
 * Signed-cookie session. We do not run a server-side session table — the
 * cookie value *is* the session, signed with the plugin secret so it cannot
 * be tampered with. TTL matches the magic-link expiry (§7.6).
 */
final class SessionCookie {

	public const NAME = 'nvf_session';

	public static function ttl(): int {
		$hours = (int) \NVF\BusBooking\Support\Settings::get( 'nvf_magic_link_expiry_hours', 24 );
		return max( 1, $hours ) * HOUR_IN_SECONDS;
	}

	public static function issue( string $email ): string {
		$token = TokenSigner::sign( $email, self::ttl(), TokenSigner::KIND_SESSION );
		setcookie( self::NAME, $token, self::cookieParams() );
		$_COOKIE[ self::NAME ] = $token; // make available in-request
		return $token;
	}

	/** @return array{email:string}|null */
	public static function read(): ?array {
		$raw = $_COOKIE[ self::NAME ] ?? null;
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		try {
			$payload = TokenSigner::verify( $raw, TokenSigner::KIND_SESSION );
		} catch ( \Throwable $e ) {
			return null;
		}
		return [ 'email' => $payload['e'] ];
	}

	public static function clear(): void {
		$params = self::cookieParams();
		$params['expires'] = time() - 3600;
		setcookie( self::NAME, '', $params );
		unset( $_COOKIE[ self::NAME ] );
	}

	/** @return array{expires:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string} */
	private static function cookieParams(): array {
		return [
			'expires'  => time() + self::ttl(),
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN ?: '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		];
	}
}
