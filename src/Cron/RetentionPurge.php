<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Cron;

use NVF\BusBooking\Booking\SeatLedger;
use NVF\BusBooking\Domain\EmailUniqueness;
use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Support\Activator;
use NVF\BusBooking\Support\Logger;
use NVF\BusBooking\Support\Settings;

/**
 * Daily retention sweep (§9.2.1).
 *
 *   cutoff_utc = event_end_date + booking_retention_days
 *
 * Any nvf_booking post with post_date_gmt < cutoff is eligible for deletion.
 * Admin-added bookings (source = admin) are preserved by default; flip the
 * `nvf_retention_purge_admin` switch to include them.
 *
 * Deleting a booking also:
 *   - releases its rows from the seat ledger,
 *   - releases its email-uniqueness lock,
 *   - cascades wp_postmeta + revisions (wp_delete_post(force=true)).
 *
 * The hook itself is registered in Activator::scheduleCron (daily).
 */
final class RetentionPurge {

	public const HOOK = Activator::CRON_HOOK;

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	public static function run(): void {
		$result = self::sweep( false );
		Logger::info( 'cron.retention_purge', $result );
	}

	/**
	 * The cutoff is a *trigger date* (event_end + retention_days). Until NOW
	 * crosses it the sweep is a no-op. After it, every booking in the system
	 * is eligible — they're all stale for this single-event plugin.
	 *
	 * @return array{cutoff_utc:string,now_utc:string,window_active:bool,considered:int,deleted:int,skipped_admin:int,dry_run:bool,override_cutoff:bool}
	 */
	public static function sweep( bool $dryRun, ?\DateTimeImmutable $overrideCutoff = null ): array {
		$cutoff = $overrideCutoff ?: self::defaultCutoff();
		$nowUtc = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		if ( ! $cutoff ) {
			Logger::warning( 'cron.retention_purge_no_event_date' );
			return self::emptyResult( '', $nowUtc, false, $dryRun, (bool) $overrideCutoff );
		}

		$cutoffUtc = $cutoff->setTimezone( new \DateTimeZone( 'UTC' ) );
		$cutoffStr = $cutoffUtc->format( 'Y-m-d H:i:s' );

		// Window not yet open — do nothing.
		if ( $nowUtc < $cutoffUtc ) {
			return self::emptyResult( $cutoffStr, $nowUtc, false, $dryRun, (bool) $overrideCutoff );
		}

		$includeAdmin = (bool) Settings::get( 'nvf_retention_purge_admin', false );

		// After the trigger date, sweep every booking — no date_query needed.
		$query = new \WP_Query( [
			'post_type'      => PostTypes::BOOKING,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		] );

		$considered   = is_array( $query->posts ) ? count( $query->posts ) : 0;
		$deleted      = 0;
		$skippedAdmin = 0;

		foreach ( $query->posts as $post ) {
			$source = (string) get_post_meta( $post->ID, 'source', true );
			if ( ! $includeAdmin && $source === 'admin' ) {
				$skippedAdmin++;
				continue;
			}
			if ( $dryRun ) {
				$deleted++;
				continue;
			}

			SeatLedger::releaseAllForBooking( $post->ID );
			EmailUniqueness::release( $post->ID );
			$removed = wp_delete_post( $post->ID, true );
			if ( $removed ) {
				$deleted++;
			}
		}
		wp_reset_postdata();

		return [
			'cutoff_utc'      => $cutoffStr,
			'now_utc'         => $nowUtc->format( 'Y-m-d H:i:s' ),
			'window_active'   => true,
			'considered'      => $considered,
			'deleted'         => $deleted,
			'skipped_admin'   => $skippedAdmin,
			'dry_run'         => $dryRun,
			'override_cutoff' => (bool) $overrideCutoff,
		];
	}

	private static function emptyResult( string $cutoffStr, \DateTimeImmutable $now, bool $active, bool $dryRun, bool $override ): array {
		return [
			'cutoff_utc'      => $cutoffStr,
			'now_utc'         => $now->format( 'Y-m-d H:i:s' ),
			'window_active'   => $active,
			'considered'      => 0,
			'deleted'         => 0,
			'skipped_admin'   => 0,
			'dry_run'         => $dryRun,
			'override_cutoff' => $override,
		];
	}

	private static function defaultCutoff(): ?\DateTimeImmutable {
		$endStr   = (string) Settings::get( 'nvf_event_end_date', '' );
		$startStr = (string) Settings::get( 'nvf_event_start_date', '' );

		try {
			if ( $endStr !== '' ) {
				$end = new \DateTimeImmutable( $endStr . ' 23:59:59', new \DateTimeZone( 'Europe/Lisbon' ) );
			} elseif ( $startStr !== '' ) {
				// Fallback: assume the event runs 4 days (per spec §4.1).
				$end = ( new \DateTimeImmutable( $startStr . ' 23:59:59', new \DateTimeZone( 'Europe/Lisbon' ) ) )->modify( '+4 days' );
			} else {
				return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		$days = max( 0, (int) Settings::get( 'nvf_booking_retention_days', 90 ) );
		return $end->modify( '+' . $days . ' days' );
	}
}
