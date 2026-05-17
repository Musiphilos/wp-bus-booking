<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Support;

/**
 * Transient-based rate limiter. Cheap and good enough for the magic-link
 * surface — the real concurrency hot path is the booking endpoint (M3),
 * which uses a SQL guard rather than this helper.
 */
final class RateLimiter {

	/**
	 * @return array{allowed:bool,count:int,limit:int,retry_after:int}
	 */
	public static function hit( string $bucket, int $limit, int $windowSeconds, ?string $identity = null ): array {
		$identity = $identity ?: self::ip();
		$key      = 'nvf_rl_' . md5( $bucket . '|' . $identity );

		$state = get_transient( $key );
		$now   = time();

		if ( ! is_array( $state ) || ( $state['reset_at'] ?? 0 ) <= $now ) {
			$state = [ 'count' => 0, 'reset_at' => $now + $windowSeconds ];
		}

		$state['count']++;
		$ttl = max( 1, $state['reset_at'] - $now );
		set_transient( $key, $state, $ttl );

		$allowed = $state['count'] <= $limit;
		return [
			'allowed'     => $allowed,
			'count'       => $state['count'],
			'limit'       => $limit,
			'retry_after' => $allowed ? 0 : $ttl,
		];
	}

	private static function ip(): string {
		$candidates = [
			$_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
			$_SERVER['HTTP_X_FORWARDED_FOR']   ?? null,
			$_SERVER['REMOTE_ADDR']            ?? null,
		];
		foreach ( $candidates as $value ) {
			if ( ! is_string( $value ) || $value === '' ) {
				continue;
			}
			// X-Forwarded-For can be a comma-separated chain; take the first.
			$first = trim( explode( ',', $value )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}
		return 'unknown';
	}
}
