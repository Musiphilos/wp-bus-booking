<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Admin;

use NVF\BusBooking\Booking\BookingReference;
use NVF\BusBooking\Domain\PostTypes;

/**
 * Custom columns on the `nvf_booking` list table. Replaces the default
 * Title-only view with a glanceable manifest-like grid.
 */
final class BookingColumns {

	public static function register(): void {
		add_filter( 'manage_' . PostTypes::BOOKING . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . PostTypes::BOOKING . '_posts_custom_column', [ self::class, 'render' ], 10, 2 );
	}

	public static function columns( array $columns ): array {
		return [
			'cb'        => $columns['cb'] ?? '',
			'ref'       => __( 'Ref', 'nvf-bus-booking' ),
			'name'      => __( 'Name', 'nvf-bus-booking' ),
			'email'     => __( 'Email', 'nvf-bus-booking' ),
			'inbound'   => __( 'Inbound', 'nvf-bus-booking' ),
			'outbound'  => __( 'Outbound', 'nvf-bus-booking' ),
			'source'    => __( 'Source', 'nvf-bus-booking' ),
			'date'      => __( 'Created', 'nvf-bus-booking' ),
		];
	}

	public static function render( string $column, int $postId ): void {
		switch ( $column ) {
			case 'ref':
				echo '<code>' . esc_html( BookingReference::for( $postId ) ) . '</code>';
				break;

			case 'name':
				$name = (string) get_post_meta( $postId, 'participant_name', true );
				echo '<strong><a href="' . esc_url( get_edit_post_link( $postId ) ) . '">' . esc_html( $name ?: '(no name)' ) . '</a></strong>';
				$phone = (string) get_post_meta( $postId, 'participant_phone', true );
				if ( $phone !== '' ) {
					echo '<div style="color:#50575e;font-size:12px;">' . esc_html( $phone ) . '</div>';
				}
				break;

			case 'email':
				$email = (string) get_post_meta( $postId, 'participant_email', true );
				echo $email !== '' ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : '—';
				break;

			case 'inbound':
				echo wp_kses_post( self::legCell( $postId, 'inbound' ) );
				break;

			case 'outbound':
				echo wp_kses_post( self::legCell( $postId, 'outbound' ) );
				break;

			case 'source':
				$src = (string) get_post_meta( $postId, 'source', true ) ?: 'public';
				echo esc_html( ucfirst( $src ) );
				break;
		}
	}

	private static function legCell( int $bookingId, string $direction ): string {
		$tripKey   = $direction . '_trip_id';
		$statusKey = $direction . '_status';
		$tripId    = (int) get_post_meta( $bookingId, $tripKey, true );
		$status    = (string) get_post_meta( $bookingId, $statusKey, true );
		if ( $tripId <= 0 || $status === '' || $status === 'none' ) {
			return '<span style="color:#a7aaad">—</span>';
		}
		$code = (string) get_post_meta( $tripId, 'trip_code', true );
		$pill = '<span class="nvf-pill nvf-pill--' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
		$line = '<strong>' . esc_html( $code ) . '</strong> ' . $pill;
		if ( $direction === 'inbound' ) {
			$pickup = (string) get_post_meta( $bookingId, 'inbound_pickup_location', true );
			if ( $pickup !== '' ) {
				$line .= '<div style="color:#50575e;font-size:12px;">' . esc_html( $pickup ) . '</div>';
			}
		}
		return $line;
	}
}
