<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Support;

/**
 * Append-only JSON-lines logger writing to wp-content/uploads/nvf-bus-booking/debug.log.
 *
 * Levels follow RFC 5424 ordering. The active level is filtered through
 * `nvf_log_min_level` (default: debug in WP_DEBUG, info otherwise).
 *
 * Files are rotated at 5 MB to a single `.1` archive (overwriting the previous one).
 * This is intentionally simple — we only need recent history, not long-term retention.
 */
final class Logger {

	public const DEBUG     = 'debug';
	public const INFO      = 'info';
	public const NOTICE    = 'notice';
	public const WARNING   = 'warning';
	public const ERROR     = 'error';
	public const CRITICAL  = 'critical';

	private const LEVEL_ORDER = [
		self::DEBUG    => 10,
		self::INFO     => 20,
		self::NOTICE   => 30,
		self::WARNING  => 40,
		self::ERROR    => 50,
		self::CRITICAL => 60,
	];

	private const MAX_BYTES = 5 * 1024 * 1024;

	public static function debug( string $event, array $context = [] ): void   { self::log( self::DEBUG, $event, $context ); }
	public static function info( string $event, array $context = [] ): void    { self::log( self::INFO, $event, $context ); }
	public static function notice( string $event, array $context = [] ): void  { self::log( self::NOTICE, $event, $context ); }
	public static function warning( string $event, array $context = [] ): void { self::log( self::WARNING, $event, $context ); }
	public static function error( string $event, array $context = [] ): void   { self::log( self::ERROR, $event, $context ); }
	public static function critical( string $event, array $context = [] ): void { self::log( self::CRITICAL, $event, $context ); }

	public static function log( string $level, string $event, array $context = [] ): void {
		$level = isset( self::LEVEL_ORDER[ $level ] ) ? $level : self::INFO;

		if ( self::LEVEL_ORDER[ $level ] < self::LEVEL_ORDER[ self::minLevel() ] ) {
			return;
		}

		$entry = [
			'ts'      => Time::nowIso(),
			'level'   => $level,
			'event'   => $event,
			'user'    => get_current_user_id() ?: null,
			'ip'      => self::clientIp(),
			'context' => self::scrub( $context ),
		];

		self::write( $entry );
	}

	public static function recent( int $lines = 50, ?string $minLevel = null ): array {
		$file = Paths::logFile();
		if ( ! is_readable( $file ) ) {
			return [];
		}

		$threshold = $minLevel && isset( self::LEVEL_ORDER[ $minLevel ] )
			? self::LEVEL_ORDER[ $minLevel ]
			: 0;

		$tail = self::tail( $file, $lines );
		$out  = [];
		foreach ( $tail as $raw ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$lvl = self::LEVEL_ORDER[ $decoded['level'] ?? '' ] ?? 0;
			if ( $lvl < $threshold ) {
				continue;
			}
			$out[] = $decoded;
		}
		return $out;
	}

	public static function clear(): bool {
		$file = Paths::logFile();
		if ( ! file_exists( $file ) ) {
			return true;
		}
		return (bool) @file_put_contents( $file, '' );
	}

	private static function write( array $entry ): void {
		$file = Paths::logFile();
		$dir  = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( file_exists( $file ) && filesize( $file ) > self::MAX_BYTES ) {
			@rename( $file, $file . '.1' );
		}

		$line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $line ) {
			return;
		}
		@file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Reads up to $limit trailing lines from a file using a simple backwards scan.
	 * Good enough for our 5 MB ceiling.
	 *
	 * @return string[] Lines in file order (oldest first).
	 */
	private static function tail( string $file, int $limit ): array {
		$fp = @fopen( $file, 'rb' );
		if ( ! $fp ) {
			return [];
		}
		try {
			fseek( $fp, 0, SEEK_END );
			$pos    = ftell( $fp );
			$chunk  = 4096;
			$buffer = '';
			$lines  = [];
			while ( $pos > 0 && count( $lines ) <= $limit ) {
				$read  = (int) min( $chunk, $pos );
				$pos  -= $read;
				fseek( $fp, $pos );
				$buffer = fread( $fp, $read ) . $buffer;
				$lines  = explode( "\n", $buffer );
			}
			$lines = array_values( array_filter( $lines, static fn( $l ) => '' !== trim( $l ) ) );
			return array_slice( $lines, -$limit );
		} finally {
			fclose( $fp );
		}
	}

	private static function minLevel(): string {
		// Default to info, even on dev — debug entries are kept for code that
		// asks for them explicitly via the `nvf_log_min_level` filter or by
		// raising the threshold programmatically. Keeps the operator log clean.
		$default = self::INFO;
		$level   = apply_filters( 'nvf_log_min_level', $default );
		return isset( self::LEVEL_ORDER[ $level ] ) ? $level : $default;
	}

	private static function clientIp(): ?string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? null;
		return is_string( $ip ) ? $ip : null;
	}

	/**
	 * Drops obvious secrets before they hit the log file.
	 */
	private static function scrub( array $context ): array {
		$blocked = [ 'password', 'pass', 'token', 'secret', 'authorization', 'cookie' ];
		foreach ( $context as $k => $v ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), $blocked, true ) ) {
				$context[ $k ] = '[redacted]';
			} elseif ( is_array( $v ) ) {
				$context[ $k ] = self::scrub( $v );
			}
		}
		return $context;
	}
}
