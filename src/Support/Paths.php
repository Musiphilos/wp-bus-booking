<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Support;

final class Paths {

	public static function uploadsDir(): string {
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( $uploads['basedir'] ?? WP_CONTENT_DIR . '/uploads' );
		return $base . 'nvf-bus-booking';
	}

	public static function logDir(): string {
		return self::uploadsDir();
	}

	public static function logFile(): string {
		return trailingslashit( self::logDir() ) . 'debug.log';
	}
}
