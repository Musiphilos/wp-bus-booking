<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Cli;

use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Support\Logger;
use WP_CLI;

/**
 * `wp nvf seed-trips` — idempotent. Creates the four launch trips listed in §4.1
 * if their trip_code is missing. Re-running the command is safe.
 */
final class SeedTripsCommand {

	private const TRIPS = [
		[
			'code'      => 'SHUTTLE-A',
			'direction' => 'OPO-IN',
			'title'     => 'SHUTTLE-A · Porto → Hotel (14:30)',
			'departure' => '2026-09-24 14:30:00',
			'stops'     => [
				[ 'label' => 'Porto Airport (Vodafone store)',    'time' => '14:30' ],
				[ 'label' => 'Terminal Alsa/Autna — Casa da Música', 'time' => '15:00' ],
				[ 'label' => 'Grande Hotel Thermas',               'time' => '17:30' ],
			],
		],
		[
			'code'      => 'SHUTTLE-B',
			'direction' => 'OPO-IN',
			'title'     => 'SHUTTLE-B · Porto → Hotel (15:30)',
			'departure' => '2026-09-24 15:30:00',
			'stops'     => [
				[ 'label' => 'Porto Airport (Vodafone store)',    'time' => '15:30' ],
				[ 'label' => 'Terminal Alsa/Autna — Casa da Música', 'time' => '16:00' ],
				[ 'label' => 'Grande Hotel Thermas',               'time' => '18:30' ],
			],
		],
		[
			'code'      => 'SHUTTLE-C',
			'direction' => 'OPO-OUT',
			'title'     => 'SHUTTLE-C · Hotel → Porto (09:00)',
			'departure' => '2026-09-28 09:00:00',
			'stops'     => [
				[ 'label' => 'Grande Hotel Thermas', 'time' => '09:00' ],
				[ 'label' => 'Porto Airport',         'time' => '11:30' ],
			],
		],
		[
			'code'      => 'SHUTTLE-D',
			'direction' => 'OPO-OUT',
			'title'     => 'SHUTTLE-D · Hotel → Porto (12:00)',
			'departure' => '2026-09-28 12:00:00',
			'stops'     => [
				[ 'label' => 'Grande Hotel Thermas',                'time' => '12:00' ],
				[ 'label' => 'Terminal Alsa/Autna — Casa da Música', 'time' => '14:30' ],
				[ 'label' => 'Porto Airport',                        'time' => '15:00' ],
			],
		],
	];

	public static function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		WP_CLI::add_command( 'nvf seed-trips', [ self::class, 'run' ] );
	}

	/**
	 * Seed the four launch shuttle trips.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Reset capacity/status on existing trips back to the defaults.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nvf seed-trips
	 *     wp nvf seed-trips --force
	 *
	 * @param array<int,string> $args
	 * @param array<string,string> $assoc_args
	 */
	public static function run( array $args, array $assoc_args ): void {
		$force = isset( $assoc_args['force'] );
		$created = 0; $updated = 0; $skipped = 0;

		foreach ( self::TRIPS as $trip ) {
			$existing = self::findByCode( $trip['code'] );
			if ( $existing && ! $force ) {
				$skipped++;
				WP_CLI::log( "skip {$trip['code']} (post #{$existing})" );
				continue;
			}

			$postId = $existing ?: wp_insert_post( [
				'post_type'   => PostTypes::TRIP,
				'post_status' => 'publish',
				'post_title'  => $trip['title'],
			], true );

			if ( is_wp_error( $postId ) ) {
				WP_CLI::error( "failed for {$trip['code']}: " . $postId->get_error_message() );
			}

			update_post_meta( $postId, 'trip_code',          $trip['code'] );
			update_post_meta( $postId, 'direction',          $trip['direction'] );
			update_post_meta( $postId, 'departure_datetime', self::normalizeLisbon( $trip['departure'] ) );
			update_post_meta( $postId, 'stops',              $trip['stops'] );
			update_post_meta( $postId, 'capacity',           55 );
			update_post_meta( $postId, 'status',             'open' );

			if ( $existing ) {
				$updated++;
				WP_CLI::log( "update {$trip['code']} (post #{$postId})" );
			} else {
				$created++;
				WP_CLI::log( "create {$trip['code']} (post #{$postId})" );
			}
		}

		Logger::info( 'cli.seed_trips', compact( 'created', 'updated', 'skipped', 'force' ) );
		WP_CLI::success( sprintf( '%d created, %d updated, %d skipped.', $created, $updated, $skipped ) );
	}

	private static function findByCode( string $code ): ?int {
		$q = new \WP_Query( [
			'post_type'      => PostTypes::TRIP,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => 'trip_code', 'value' => $code, 'compare' => '=' ] ],
		] );
		$id = $q->posts[0] ?? null;
		wp_reset_postdata();
		return $id ? (int) $id : null;
	}

	/**
	 * Normalize a Europe/Lisbon wall-clock string into the `Y-m-d H:i:s` shape
	 * Meta Box uses for `datetime` fields. We store the value as-is (Lisbon
	 * time) so what the admin sees matches what the database holds.
	 */
	private static function normalizeLisbon( string $lisbon ): string {
		$dt = new \DateTimeImmutable( $lisbon, \NVF\BusBooking\Support\Time::zone() );
		return $dt->format( 'Y-m-d H:i:s' );
	}
}
