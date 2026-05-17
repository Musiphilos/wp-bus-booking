<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Domain;

use NVF\BusBooking\Admin\AdminMenu;

/**
 * Custom post types for the booking domain.
 *
 * Both types are admin-only — they never appear on the public site.
 * UI lives under our top-level "Bus Booking" menu (via `show_in_menu`).
 */
final class PostTypes {

	public const TRIP    = 'nvf_trip';
	public const BOOKING = 'nvf_booking';

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerTrip' ] );
		add_action( 'init', [ self::class, 'registerBooking' ] );
	}

	public static function registerTrip(): void {
		register_post_type( self::TRIP, [
			'labels' => [
				'name'               => __( 'Trips', 'nvf-bus-booking' ),
				'singular_name'      => __( 'Trip', 'nvf-bus-booking' ),
				'add_new'            => __( 'Add Trip', 'nvf-bus-booking' ),
				'add_new_item'       => __( 'Add New Trip', 'nvf-bus-booking' ),
				'edit_item'          => __( 'Edit Trip', 'nvf-bus-booking' ),
				'new_item'           => __( 'New Trip', 'nvf-bus-booking' ),
				'view_item'          => __( 'View Trip', 'nvf-bus-booking' ),
				'search_items'       => __( 'Search Trips', 'nvf-bus-booking' ),
				'not_found'          => __( 'No trips found.', 'nvf-bus-booking' ),
				'menu_name'          => __( 'Trips', 'nvf-bus-booking' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => AdminMenu::SLUG,
			'show_in_rest'        => false,
			'supports'            => [ 'title' ],
			'capability_type'     => 'page',
			'map_meta_cap'        => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'exclude_from_search' => true,
		] );
	}

	public static function registerBooking(): void {
		register_post_type( self::BOOKING, [
			'labels' => [
				'name'               => __( 'Bookings', 'nvf-bus-booking' ),
				'singular_name'      => __( 'Booking', 'nvf-bus-booking' ),
				'add_new'            => __( 'Add Booking', 'nvf-bus-booking' ),
				'add_new_item'       => __( 'Add New Booking', 'nvf-bus-booking' ),
				'edit_item'          => __( 'Edit Booking', 'nvf-bus-booking' ),
				'new_item'           => __( 'New Booking', 'nvf-bus-booking' ),
				'view_item'          => __( 'View Booking', 'nvf-bus-booking' ),
				'search_items'       => __( 'Search Bookings', 'nvf-bus-booking' ),
				'not_found'          => __( 'No bookings found.', 'nvf-bus-booking' ),
				'menu_name'          => __( 'Bookings', 'nvf-bus-booking' ),
			],
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => AdminMenu::SLUG,
			'show_in_rest'        => false,
			'supports'            => [ 'title' ],
			'capability_type'     => 'page',
			'map_meta_cap'        => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'exclude_from_search' => true,
		] );
	}
}
