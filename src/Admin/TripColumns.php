<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Admin;

use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Domain\TripCounts;
use NVF\BusBooking\Support\Time;

/**
 * Adds Trip-code / Direction / Date / Seats / Status columns to the trips
 * list table, replacing the default Title column ordering.
 */
final class TripColumns {

	public static function register(): void {
		add_filter( 'manage_' . PostTypes::TRIP . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . PostTypes::TRIP . '_posts_custom_column', [ self::class, 'render' ], 10, 2 );
	}

	public static function columns( array $columns ): array {
		$new = [
			'cb'           => $columns['cb'] ?? '',
			'title'        => __( 'Name', 'nvf-bus-booking' ),
			'trip_code'    => __( 'Code', 'nvf-bus-booking' ),
			'direction'    => __( 'Direction', 'nvf-bus-booking' ),
			'departure'    => __( 'Departure', 'nvf-bus-booking' ),
			'seats'        => __( 'Seats', 'nvf-bus-booking' ),
			'waitlist'     => __( 'Waitlist', 'nvf-bus-booking' ),
			'trip_status'  => __( 'Status', 'nvf-bus-booking' ),
		];
		return $new;
	}

	public static function render( string $column, int $postId ): void {
		switch ( $column ) {
			case 'trip_code':
				echo esc_html( (string) get_post_meta( $postId, 'trip_code', true ) );
				break;

			case 'direction':
				echo esc_html( (string) get_post_meta( $postId, 'direction', true ) );
				break;

			case 'departure':
				$raw = (string) get_post_meta( $postId, 'departure_datetime', true );
				echo $raw === '' ? '—' : esc_html( self::formatLisbon( $raw ) );
				break;

			case 'seats':
				$counts = TripCounts::forTrip( $postId );
				$pct    = $counts['capacity'] > 0 ? min( 100, (int) round( $counts['confirmed'] / $counts['capacity'] * 100 ) ) : 0;
				$capCls = $pct >= 100 ? 'full' : ( $pct >= 80 ? 'mid' : '' );
				printf(
					'<div class="nvf-cap nvf-cap--%s"><span class="nvf-cap__label">%d / %d <small>(%d%%)</small></span><div class="nvf-cap__track"><div class="nvf-cap__fill" style="width:%d%%"></div></div></div>',
					esc_attr( $capCls ),
					(int) $counts['confirmed'],
					(int) $counts['capacity'],
					(int) $pct,
					(int) $pct
				);
				break;

			case 'waitlist':
				$counts = TripCounts::forTrip( $postId );
				echo (int) $counts['waitlist'];
				break;

			case 'trip_status':
				$status  = (string) get_post_meta( $postId, 'status', true );
				$pretty  = [ 'open' => 'Open', 'full' => 'Full', 'cancelled' => 'Cancelled' ][ $status ] ?? $status;
				$pillCls = $status === 'cancelled' ? 'cancelled-trip' : ( $status === 'full' ? 'full' : 'open' );
				printf(
					'<span class="nvf-pill nvf-pill--%s">%s</span>',
					esc_attr( $pillCls ),
					esc_html( $pretty )
				);
				break;
		}
	}

	private static function formatLisbon( string $dateTime ): string {
		$formatted = Time::formatHuman( $dateTime );
		return $formatted !== '' ? $formatted : $dateTime;
	}
}
