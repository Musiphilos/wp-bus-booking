<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Cli;

use NVF\BusBooking\Booking\LedgerReconciler;
use WP_CLI;

/**
 * `wp nvf reconcile-ledger` — rebuild the seat ledger from confirmed booking
 * meta so availability (booking page, Dashboard, Manifest, Trips column) matches
 * the actual confirmed bookings. Idempotent; safe to run any time.
 */
final class ReconcileLedgerCommand {

	public static function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		WP_CLI::add_command( 'nvf reconcile-ledger', [ self::class, 'run' ] );
	}

	/**
	 * Reconcile the seat ledger against confirmed booking meta.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nvf reconcile-ledger
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public static function run( array $args, array $assoc_args ): void {
		$res = LedgerReconciler::run();
		WP_CLI::log( sprintf( 'confirmed legs: %d', $res['confirmed_legs'] ) );
		WP_CLI::log( sprintf( 'rows inserted:  %d', $res['inserted'] ) );
		WP_CLI::log( sprintf( 'rows deleted:   %d', $res['deleted'] ) );
		WP_CLI::success( 'Seat ledger reconciled.' );
	}
}
