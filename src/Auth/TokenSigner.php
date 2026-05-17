<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Auth;

/**
 * Stateless, HMAC-signed token for magic-link verification and the session cookie.
 *
 * Format:  base64url( json_payload ) . "." . base64url( hmac_sha256( payload, secret ) )
 *
 * Payload fields:
 *   v   — schema version (currently 1)
 *   e   — email (lowercased, trimmed)
 *   exp — UNIX timestamp (seconds) after which the token is invalid
 *   k   — token kind: "link" (single-use magic link) or "session" (cookie)
 *   n   — random 16-byte nonce, base64url. Single-use guard for "link".
 */
final class TokenSigner {

	public const VERSION       = 1;
	public const KIND_LINK     = 'link';
	public const KIND_SESSION  = 'session';
	public const KIND_CLAIM    = 'claim';

	/**
	 * @param array<string,scalar> $extras Optional extra payload keys. Reserved
	 *        keys (v, e, exp, k, n) are silently overwritten by the canonical fields.
	 */
	public static function sign( string $email, int $ttlSeconds, string $kind, array $extras = [] ): string {
		$payload = array_merge( $extras, [
			'v'   => self::VERSION,
			'e'   => self::normalizeEmail( $email ),
			'exp' => time() + max( 1, $ttlSeconds ),
			'k'   => $kind,
			'n'   => self::randomNonce(),
		] );

		$payloadJson = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $payloadJson ) {
			throw new \RuntimeException( 'Failed to encode token payload.' );
		}

		$payloadEnc = self::base64UrlEncode( $payloadJson );
		$signature  = self::sign_raw( $payloadEnc );

		return $payloadEnc . '.' . $signature;
	}

	/**
	 * @return array{v:int,e:string,exp:int,k:string,n:string}
	 *
	 * @throws \RuntimeException on shape error, bad signature, expired token, or kind mismatch.
	 */
	public static function verify( string $token, string $expectedKind ): array {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 2 ) {
			throw new \RuntimeException( 'Malformed token.' );
		}
		[ $payloadEnc, $sig ] = $parts;

		$expected = self::sign_raw( $payloadEnc );
		if ( ! hash_equals( $expected, $sig ) ) {
			throw new \RuntimeException( 'Bad signature.' );
		}

		$payloadJson = self::base64UrlDecode( $payloadEnc );
		$payload     = json_decode( $payloadJson, true );
		if ( ! is_array( $payload ) || ! isset( $payload['v'], $payload['e'], $payload['exp'], $payload['k'], $payload['n'] ) ) {
			throw new \RuntimeException( 'Malformed payload.' );
		}
		if ( (int) $payload['v'] !== self::VERSION ) {
			throw new \RuntimeException( 'Unsupported token version.' );
		}
		if ( (int) $payload['exp'] < time() ) {
			throw new \RuntimeException( 'Token expired.' );
		}
		if ( (string) $payload['k'] !== $expectedKind ) {
			throw new \RuntimeException( 'Unexpected token kind.' );
		}

		// Preserve any extra fields the signer included (e.g. claim links carry
		// trip_id + direction). Reserved keys are normalised to their typed shape.
		$payload['v']   = (int) $payload['v'];
		$payload['e']   = (string) $payload['e'];
		$payload['exp'] = (int) $payload['exp'];
		$payload['k']   = (string) $payload['k'];
		$payload['n']   = (string) $payload['n'];
		return $payload;
	}

	private static function sign_raw( string $payloadEnc ): string {
		$secret = (string) get_option( 'nvf_plugin_secret', '' );
		if ( $secret === '' ) {
			throw new \RuntimeException( 'Plugin secret is missing — re-activate the plugin.' );
		}
		return self::base64UrlEncode( hash_hmac( 'sha256', $payloadEnc, $secret, true ) );
	}

	private static function normalizeEmail( string $email ): string {
		return strtolower( trim( $email ) );
	}

	private static function randomNonce(): string {
		try {
			$bytes = random_bytes( 12 );
		} catch ( \Throwable $e ) {
			$bytes = openssl_random_pseudo_bytes( 12 ) ?: substr( wp_generate_password( 24, false ), 0, 12 );
		}
		return self::base64UrlEncode( $bytes );
	}

	public static function base64UrlEncode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function base64UrlDecode( string $data ): string {
		$pad = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
		if ( false === $decoded ) {
			throw new \RuntimeException( 'Bad base64url payload.' );
		}
		return $decoded;
	}
}
