<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Domain;

/**
 * Meta Box field definitions for both CPTs.
 *
 * Field IDs are intentionally prefix-free since each meta box already lives
 * on a distinct post type. The keys here match `meta_key` rows in `wp_postmeta`
 * and are the contract used by the booking service, REST handlers, and MCP
 * abilities downstream.
 */
final class MetaBoxes {

	public const TRIP_CODES = [ 'SHUTTLE-A', 'SHUTTLE-B', 'SHUTTLE-C', 'SHUTTLE-D' ];

	public const DIRECTIONS = [
		'OPO-IN'  => 'OPO-IN (Inbound — Sep 24)',
		'OPO-OUT' => 'OPO-OUT (Outbound — Sep 28)',
	];

	public const TRIP_STATUSES = [
		'open'      => 'Open',
		'full'      => 'Full (auto)',
		'cancelled' => 'Cancelled',
	];

	public const DIRECTION_STATUSES = [
		'none'      => 'None',
		'confirmed' => 'Confirmed',
		'waitlist'  => 'Waitlist',
		'cancelled' => 'Cancelled',
	];

	public const PICKUP_LOCATIONS = [
		'airport'        => 'Porto Airport (Vodafone store)',
		'casa_da_musica' => 'Terminal Alsa/Autna — Casa da Música',
	];

	public const BOOKING_SOURCES = [
		'public' => 'Public (self-booked)',
		'admin'  => 'Admin (manually added)',
	];

	public static function register(): void {
		add_filter( 'rwmb_meta_boxes', [ self::class, 'boxes' ] );
	}

	/**
	 * @param array<int,array> $meta_boxes
	 * @return array<int,array>
	 */
	public static function boxes( array $meta_boxes ): array {
		$meta_boxes[] = self::tripBox();
		$meta_boxes[] = self::bookingBox();
		return $meta_boxes;
	}

	private static function tripBox(): array {
		return [
			'id'         => 'nvf_trip_details',
			'title'      => __( 'Trip Details', 'nvf-bus-booking' ),
			'post_types' => [ PostTypes::TRIP ],
			'context'    => 'normal',
			'priority'   => 'high',
			'fields'     => [
				[
					'id'       => 'trip_code',
					'name'     => __( 'Trip code', 'nvf-bus-booking' ),
					'type'     => 'select',
					'options'  => array_combine( self::TRIP_CODES, self::TRIP_CODES ),
					'required' => true,
				],
				[
					'id'       => 'direction',
					'name'     => __( 'Direction', 'nvf-bus-booking' ),
					'type'     => 'select',
					'options'  => self::DIRECTIONS,
					'required' => true,
				],
				[
					'id'         => 'departure_datetime',
					'name'       => __( 'Departure (Europe/Lisbon)', 'nvf-bus-booking' ),
					'type'       => 'datetime',
					'timestamp'  => false,
					'js_options' => [
						'stepMinute' => 5,
						'showButtonPanel' => false,
					],
					'required'   => true,
					'desc'       => __( 'Stored as Europe/Lisbon wall-clock time (no timezone conversion).', 'nvf-bus-booking' ),
				],
				[
					'id'    => 'stops',
					'name'  => __( 'Stops', 'nvf-bus-booking' ),
					'type'  => 'group',
					'clone' => true,
					'sort_clone' => true,
					'fields' => [
						[ 'id' => 'label', 'name' => __( 'Stop', 'nvf-bus-booking' ), 'type' => 'text' ],
						[ 'id' => 'time',  'name' => __( 'Time',  'nvf-bus-booking' ), 'type' => 'time' ],
					],
				],
				[
					'id'      => 'capacity',
					'name'    => __( 'Capacity', 'nvf-bus-booking' ),
					'type'    => 'number',
					'std'     => 55,
					'min'     => 1,
					'max'     => 200,
				],
				[
					'id'   => 'price',
					'name' => __( 'Price (informational)', 'nvf-bus-booking' ),
					'type' => 'number',
					'step' => '0.01',
				],
				[
					'id'      => 'status',
					'name'    => __( 'Status', 'nvf-bus-booking' ),
					'type'    => 'select',
					'options' => self::TRIP_STATUSES,
					'std'     => 'open',
					'desc'    => __( '"Full" is auto-computed in admin columns; set to "Cancelled" to take a trip offline.', 'nvf-bus-booking' ),
				],
			],
		];
	}

	private static function bookingBox(): array {
		return [
			'id'         => 'nvf_booking_details',
			'title'      => __( 'Booking Details', 'nvf-bus-booking' ),
			'post_types' => [ PostTypes::BOOKING ],
			'context'    => 'normal',
			'priority'   => 'high',
			'fields'     => [
				[
					'id'       => 'participant_email',
					'name'     => __( 'Email', 'nvf-bus-booking' ),
					'type'     => 'email',
					'required' => true,
					'desc'     => __( 'Must be unique across all bookings.', 'nvf-bus-booking' ),
				],
				[
					'id'   => 'participant_name',
					'name' => __( 'Name', 'nvf-bus-booking' ),
					'type' => 'text',
				],
				[
					'id'   => 'participant_phone',
					'name' => __( 'Phone', 'nvf-bus-booking' ),
					'type' => 'tel',
				],
				// --- Inbound -------------------------------------------------------------
				[
					'type' => 'heading',
					'name' => __( 'Inbound (Sep 24)', 'nvf-bus-booking' ),
				],
				[
					'id'          => 'inbound_trip_id',
					'name'        => __( 'Inbound trip', 'nvf-bus-booking' ),
					'type'        => 'post',
					'post_type'   => PostTypes::TRIP,
					'field_type'  => 'select_advanced',
					'placeholder' => __( '— None —', 'nvf-bus-booking' ),
					'query_args'  => [
						'meta_query' => [
							[ 'key' => 'direction', 'value' => 'OPO-IN' ],
						],
					],
				],
				[
					'id'      => 'inbound_status',
					'name'    => __( 'Inbound status', 'nvf-bus-booking' ),
					'type'    => 'select',
					'options' => self::DIRECTION_STATUSES,
					'std'     => 'none',
				],
				[
					'id'         => 'inbound_pickup_location',
					'name'       => __( 'Inbound pickup location', 'nvf-bus-booking' ),
					'type'       => 'select',
					'options'    => self::PICKUP_LOCATIONS,
					'placeholder'=> __( '— Select —', 'nvf-bus-booking' ),
					'visible'    => [ 'inbound_trip_id', '!=', '' ],
				],
				[
					'id'   => 'inbound_waitlist_position',
					'name' => __( 'Inbound waitlist position', 'nvf-bus-booking' ),
					'type' => 'number',
					'min'  => 0,
				],
				// --- Outbound ------------------------------------------------------------
				[
					'type' => 'heading',
					'name' => __( 'Outbound (Sep 28)', 'nvf-bus-booking' ),
				],
				[
					'id'          => 'outbound_trip_id',
					'name'        => __( 'Outbound trip', 'nvf-bus-booking' ),
					'type'        => 'post',
					'post_type'   => PostTypes::TRIP,
					'field_type'  => 'select_advanced',
					'placeholder' => __( '— None —', 'nvf-bus-booking' ),
					'query_args'  => [
						'meta_query' => [
							[ 'key' => 'direction', 'value' => 'OPO-OUT' ],
						],
					],
				],
				[
					'id'      => 'outbound_status',
					'name'    => __( 'Outbound status', 'nvf-bus-booking' ),
					'type'    => 'select',
					'options' => self::DIRECTION_STATUSES,
					'std'     => 'none',
				],
				[
					'id'   => 'outbound_waitlist_position',
					'name' => __( 'Outbound waitlist position', 'nvf-bus-booking' ),
					'type' => 'number',
					'min'  => 0,
				],
				// --- Meta ---------------------------------------------------------------
				[
					'type' => 'heading',
					'name' => __( 'Compliance & audit', 'nvf-bus-booking' ),
				],
				[
					'id'        => 'gdpr_accepted_at',
					'name'      => __( 'GDPR accepted at', 'nvf-bus-booking' ),
					'type'      => 'datetime',
					'timestamp' => false,
					'desc'      => __( 'Set automatically on public bookings; required for record-keeping.', 'nvf-bus-booking' ),
				],
				[
					'id'      => 'source',
					'name'    => __( 'Source', 'nvf-bus-booking' ),
					'type'    => 'select',
					'options' => self::BOOKING_SOURCES,
					'std'     => 'admin',
				],
				[
					'id'         => 'history',
					'name'       => __( 'History', 'nvf-bus-booking' ),
					'type'       => 'group',
					'clone'      => true,
					'sort_clone' => true,
					'collapsible'=> true,
					'group_title'=> [ 'field' => 'action' ],
					'fields'     => [
						[ 'id' => 'timestamp', 'name' => __( 'Timestamp', 'nvf-bus-booking' ), 'type' => 'datetime', 'timestamp' => false ],
						[ 'id' => 'actor',     'name' => __( 'Actor',     'nvf-bus-booking' ), 'type' => 'text' ],
						[ 'id' => 'action',    'name' => __( 'Action',    'nvf-bus-booking' ), 'type' => 'text' ],
						[ 'id' => 'note',      'name' => __( 'Note',      'nvf-bus-booking' ), 'type' => 'textarea', 'rows' => 2 ],
					],
				],
			],
		];
	}
}
