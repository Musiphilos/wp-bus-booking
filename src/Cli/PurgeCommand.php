<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Cli;

use NVF\BusBooking\Cron\RetentionPurge;
use WP_CLI;

/**
 * `wp nvf purge` — manually exercise the retention sweep. Defaults to a
 * dry-run; pass --commit to actually delete. --cutoff overrides the computed
 * date for ad-hoc audits ("show me what would be deleted on 2027-01-01").
 */
final class PurgeCommand {

	public static function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		WP_CLI::add_command( 'nvf purge', [ self::class, 'run' ] );
	}

	/**
	 * Manually exercise the retention sweep.
	 *
	 * ## OPTIONS
	 *
	 * [--commit]
	 * : Actually delete the matched bookings. Without this flag the command is a dry-run.
	 *
	 * [--cutoff=<date>]
	 * : YYYY-MM-DD override for the cutoff. Defaults to event_end_date + booking_retention_days.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nvf purge                          # dry-run with the configured cutoff
	 *     wp nvf purge --commit                 # actually delete eligible bookings
	 *     wp nvf purge --cutoff=2026-12-01      # show what would be deleted with a hypothetical cutoff
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public static function run( array $args, array $assoc_args ): void {
		$dryRun = ! isset( $assoc_args['commit'] );
		$override = null;
		if ( ! empty( $assoc_args['cutoff'] ) ) {
			try {
				$override = new \DateTimeImmutable( $assoc_args['cutoff'] . ' 23:59:59', \NVF\BusBooking\Support\Time::zone() );
			} catch ( \Throwable $e ) {
				WP_CLI::error( 'Bad --cutoff: ' . $e->getMessage() );
			}
		}

		$res = RetentionPurge::sweep( $dryRun, $override );

		WP_CLI::log( sprintf( 'cutoff (Lisbon): %s', $res['cutoff'] ?: '(none — set event_end_date or event_start_date)' ) );
		WP_CLI::log( sprintf( 'considered:   %d', $res['considered'] ) );
		WP_CLI::log( sprintf( 'eligible:     %d', $res['deleted'] ) );
		WP_CLI::log( sprintf( 'skipped admin:%d', $res['skipped_admin'] ) );

		if ( $dryRun ) {
			WP_CLI::success( 'Dry-run. Pass --commit to actually delete.' );
			return;
		}
		WP_CLI::success( sprintf( 'Deleted %d booking(s).', $res['deleted'] ) );
	}
}
