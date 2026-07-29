<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Support;

use NVF\BusBooking\Booking\SeatLedger;
use NVF\BusBooking\Domain\EmailUniqueness;

/**
 * Activation / deactivation routines.
 *
 * - Generates the HMAC secret used by future magic-link tokens (M2).
 * - Creates the uploads log directory with an index.html guard.
 * - Installs the booking-email uniqueness table.
 * - Schedules the daily retention purge cron (handler wired in M7).
 */
final class Activator {

	public const CRON_HOOK = 'nvf_retention_purge';

	public static function run(): void {
		self::ensureSecret();
		self::ensureLogDir();
		EmailUniqueness::install();
		SeatLedger::install();
		self::scheduleCron();
	}

	public static function tearDown(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Provision the HMAC signing secret if it is absent.
	 *
	 * Called both on activation and on every boot (see Plugin::boot). The boot
	 * call makes the plugin self-heal when its files are copied to a new site
	 * without a clean activation — e.g. a dev→prod migration — which would
	 * otherwise leave `nvf_plugin_secret` empty and make TokenSigner::sign()
	 * throw an uncaught exception on the magic-link path. Idempotent: a single
	 * cached get_option when the secret already exists.
	 */
	public static function ensureSecret(): void {
		if ( get_option( 'nvf_plugin_secret' ) ) {
			return;
		}
		try {
			$secret = base64_encode( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			$secret = wp_generate_password( 64, true, true );
		}
		add_option( 'nvf_plugin_secret', $secret, '', false );
	}

	private static function ensureLogDir(): void {
		$dir = Paths::logDir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$guard = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $guard ) ) {
			@file_put_contents( $guard, '' );
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Order allow,deny\nDeny from all\n" );
		}
	}

	private static function scheduleCron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}
}
