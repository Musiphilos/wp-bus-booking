<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Admin;

use NVF\BusBooking\Booking\SeatLedger;
use NVF\BusBooking\Domain\PostTypes;

/**
 * Replaces the M0 placeholder render. Shows roll-up counts at the top and a
 * per-trip table where every row links to the printable manifest + CSV export.
 */
final class Dashboard {

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'nvf-bus-booking' ) );
		}

		$trips     = self::loadTrips();
		$totals    = self::rollups( $trips );
		?>
		<div class="wrap nvf-admin">
			<h1><?php esc_html_e( 'Bus Booking', 'nvf-bus-booking' ); ?></h1>

			<div class="nvf-admin__stats">
				<?php
				self::stat( __( 'Total bookings', 'nvf-bus-booking' ), (string) $totals['bookings'] );
				self::stat( __( 'Seats confirmed', 'nvf-bus-booking' ), $totals['confirmed'] . ' / ' . $totals['capacity'] );
				self::stat( __( 'On waiting list', 'nvf-bus-booking' ), (string) $totals['waitlist'] );
				self::stat( __( 'Available seats', 'nvf-bus-booking' ), (string) max( 0, $totals['capacity'] - $totals['confirmed'] ) );
				?>
			</div>

			<h2><?php esc_html_e( 'Trips', 'nvf-bus-booking' ); ?></h2>
			<table class="widefat striped nvf-admin__trips">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Code', 'nvf-bus-booking' ); ?></th>
						<th><?php esc_html_e( 'Direction', 'nvf-bus-booking' ); ?></th>
						<th><?php esc_html_e( 'Departure (Lisbon)', 'nvf-bus-booking' ); ?></th>
						<th><?php esc_html_e( 'Confirmed', 'nvf-bus-booking' ); ?></th>
						<th><?php esc_html_e( 'Waitlist', 'nvf-bus-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'nvf-bus-booking' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'nvf-bus-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $trips as $t ) :
						$pct   = $t['capacity'] > 0 ? min( 100, (int) round( $t['confirmed'] / $t['capacity'] * 100 ) ) : 0;
					?>
						<tr>
							<td><strong><?php echo esc_html( $t['code'] ); ?></strong></td>
							<td><?php echo esc_html( $t['direction'] ); ?></td>
							<td><?php echo esc_html( $t['departure'] ); ?></td>
							<td>
								<?php $capCls = $pct >= 100 ? 'full' : ( $pct >= 80 ? 'mid' : '' ); ?>
								<div class="nvf-cap nvf-cap--<?php echo esc_attr( $capCls ); ?>">
									<span class="nvf-cap__label"><?php echo (int) $t['confirmed']; ?> / <?php echo (int) $t['capacity']; ?></span>
									<div class="nvf-cap__track">
										<div class="nvf-cap__fill" style="width:<?php echo esc_attr( (string) min( 100, $pct ) ); ?>%"></div>
									</div>
								</div>
							</td>
							<td><?php echo (int) $t['waitlist']; ?></td>
							<td>
								<?php
								if ( $t['raw_status'] === 'cancelled' ) {
									$pillCls = 'cancelled-trip';
								} elseif ( $t['capacity'] > 0 && $t['confirmed'] >= $t['capacity'] ) {
									$pillCls = 'full';
								} else {
									$pillCls = 'open';
								}
								?>
								<span class="nvf-pill nvf-pill--<?php echo esc_attr( $pillCls ); ?>">
									<?php echo esc_html( $t['status_label'] ); ?>
								</span>
							</td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( ManifestPage::manifestUrl( $t['id'] ) ); ?>">
									<?php esc_html_e( 'Manifest', 'nvf-bus-booking' ); ?>
								</a>
								<a class="button button-small" href="<?php echo esc_url( ManifestPage::csvUrl( $t['id'] ) ); ?>">
									<?php esc_html_e( 'CSV', 'nvf-bus-booking' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php
	}

	private static function stat( string $label, string $value ): void {
		echo '<div class="nvf-admin__stat"><div class="nvf-admin__stat-label">' . esc_html( $label ) . '</div><div class="nvf-admin__stat-value">' . esc_html( $value ) . '</div></div>';
	}

	/** @return array<int,array> */
	private static function loadTrips(): array {
		$query = new \WP_Query( [
			'post_type'      => PostTypes::TRIP,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'no_found_rows'  => true,
			'orderby'        => 'meta_value',
			'meta_key'       => 'departure_datetime',
			'order'          => 'ASC',
		] );
		$out = [];
		foreach ( $query->posts as $post ) {
			$capacity  = (int) ( get_post_meta( $post->ID, 'capacity', true ) ?: 0 );
			$confirmed = SeatLedger::countConfirmed( $post->ID );
			$rawStatus = (string) get_post_meta( $post->ID, 'status', true );
			$out[] = [
				'id'           => $post->ID,
				'code'         => (string) get_post_meta( $post->ID, 'trip_code', true ),
				'direction'    => (string) get_post_meta( $post->ID, 'direction', true ),
				'departure'    => self::lisbonHuman( (string) get_post_meta( $post->ID, 'departure_datetime', true ) ),
				'capacity'     => $capacity,
				'confirmed'    => $confirmed,
				'waitlist'     => self::countWaitlist( $post->ID ),
				'raw_status'   => $rawStatus,
				'status_label' => self::statusLabel( $rawStatus, $confirmed, $capacity ),
			];
		}
		wp_reset_postdata();
		return $out;
	}

	/** @param array<int,array> $trips */
	private static function rollups( array $trips ): array {
		$totals = [ 'bookings' => 0, 'confirmed' => 0, 'capacity' => 0, 'waitlist' => 0 ];
		foreach ( $trips as $t ) {
			$totals['confirmed'] += $t['confirmed'];
			$totals['capacity']  += $t['capacity'];
			$totals['waitlist']  += $t['waitlist'];
		}
		$totals['bookings'] = (int) ( new \WP_Query( [
			'post_type'      => PostTypes::BOOKING,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		] ) )->found_posts;
		// `found_posts` was 0 above because of posts_per_page=-1; recount via WP_Query without that flag.
		$totals['bookings'] = (int) ( new \WP_Query( [
			'post_type'   => PostTypes::BOOKING,
			'post_status' => 'publish',
			'fields'      => 'ids',
			'posts_per_page' => 1,
		] ) )->found_posts;
		return $totals;
	}

	private static function countWaitlist( int $tripId ): int {
		$direction = (string) get_post_meta( $tripId, 'direction', true );
		$isInbound = str_starts_with( $direction, 'OPO-IN' );
		$tripKey   = $isInbound ? 'inbound_trip_id' : 'outbound_trip_id';
		$statusKey = $isInbound ? 'inbound_status'  : 'outbound_status';
		$q = new \WP_Query( [
			'post_type'      => PostTypes::BOOKING,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => $tripKey,   'value' => $tripId,   'compare' => '=' ],
				[ 'key' => $statusKey, 'value' => 'waitlist', 'compare' => '=' ],
			],
		] );
		$n = is_array( $q->posts ) ? count( $q->posts ) : 0;
		wp_reset_postdata();
		return $n;
	}

	private static function statusLabel( string $raw, int $confirmed, int $capacity ): string {
		if ( $raw === 'cancelled' ) {
			return __( 'Cancelled', 'nvf-bus-booking' );
		}
		if ( $capacity > 0 && $confirmed >= $capacity ) {
			return __( 'Full', 'nvf-bus-booking' );
		}
		return __( 'Open', 'nvf-bus-booking' );
	}

	private static function lisbonHuman( string $dt ): string {
		if ( $dt === '' ) {
			return '—';
		}
		$formatted = \NVF\BusBooking\Support\Time::formatHuman( $dt );
		return $formatted !== '' ? $formatted : $dt;
	}
}
