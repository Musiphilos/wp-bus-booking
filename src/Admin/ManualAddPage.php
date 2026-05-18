<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Admin;

use NVF\BusBooking\Booking\BookingService;
use NVF\BusBooking\Domain\PostTypes;

/**
 * Admin → Bus Booking → Add Booking. Lets the team enter a booking on someone's
 * behalf (team members, day-of additions). Bypasses Elementor verification and,
 * if "override capacity" is ticked, the SeatLedger capacity guard.
 */
final class ManualAddPage {

	public const SLUG = 'nvf-bus-booking-add';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 25 );
		add_action( 'admin_post_nvf_manual_add', [ self::class, 'handleSubmit' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			AdminMenu::SLUG,
			__( 'Add Booking', 'nvf-bus-booking' ),
			__( 'Add Booking', 'nvf-bus-booking' ),
			AdminMenu::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'nvf-bus-booking' ) );
		}

		$inboundTrips  = self::trips( 'OPO-IN' );
		$outboundTrips = self::trips( 'OPO-OUT' );
		$flash         = isset( $_GET['nvf_added'] ) ? sanitize_key( wp_unslash( $_GET['nvf_added'] ) ) : '';

		?>
		<div class="wrap nvf-admin">
			<h1><?php esc_html_e( 'Add Booking', 'nvf-bus-booking' ); ?></h1>
			<p><?php esc_html_e( 'Manually add a booking. The Elementor registration check is bypassed; tick "override capacity" only when intentionally seating team members beyond the published cap.', 'nvf-bus-booking' ); ?></p>

			<?php if ( $flash === 'ok' ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Booking saved and confirmation email sent.', 'nvf-bus-booking' ); ?></p></div>
			<?php elseif ( $flash !== '' ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( str_replace( '_', ' ', $flash ) ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvf-add-form">
				<?php wp_nonce_field( 'nvf_manual_add' ); ?>
				<input type="hidden" name="action" value="nvf_manual_add" />

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="nvf_email"><?php esc_html_e( 'Email', 'nvf-bus-booking' ); ?> *</label></th>
						<td><input class="regular-text" type="email" id="nvf_email" name="email" required /></td>
					</tr>
					<tr>
						<th><label for="nvf_name"><?php esc_html_e( 'Name', 'nvf-bus-booking' ); ?></label></th>
						<td><input class="regular-text" type="text" id="nvf_name" name="name" /></td>
					</tr>
					<tr>
						<th><label for="nvf_phone"><?php esc_html_e( 'Phone', 'nvf-bus-booking' ); ?></label></th>
						<td><input class="regular-text" type="text" id="nvf_phone" name="phone" /></td>
					</tr>
					<tr>
						<th><label for="nvf_inbound"><?php esc_html_e( 'Inbound trip', 'nvf-bus-booking' ); ?></label></th>
						<td>
							<select id="nvf_inbound" name="inbound_trip">
								<option value="0"><?php esc_html_e( '— None —', 'nvf-bus-booking' ); ?></option>
								<?php foreach ( $inboundTrips as $t ) : ?>
									<option value="<?php echo (int) $t['id']; ?>"><?php echo esc_html( $t['code'] . ' · ' . $t['departure'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<span id="nvf-pickup-wrap" style="margin-left:10px;display:none;">
								<select name="inbound_pickup" id="nvf_inbound_pickup">
									<option value=""><?php esc_html_e( '— Pickup —', 'nvf-bus-booking' ); ?></option>
									<?php foreach ( \NVF\BusBooking\Domain\MetaBoxes::PICKUP_LOCATIONS as $pickupKey => $pickupLabel ) : ?>
										<option value="<?php echo esc_attr( $pickupKey ); ?>"><?php echo esc_html( $pickupLabel ); ?></option>
									<?php endforeach; ?>
								</select>
							</span>
						</td>
					</tr>
					<tr>
						<th><label for="nvf_outbound"><?php esc_html_e( 'Outbound trip', 'nvf-bus-booking' ); ?></label></th>
						<td>
							<select id="nvf_outbound" name="outbound_trip">
								<option value="0"><?php esc_html_e( '— None —', 'nvf-bus-booking' ); ?></option>
								<?php foreach ( $outboundTrips as $t ) : ?>
									<option value="<?php echo (int) $t['id']; ?>"><?php echo esc_html( $t['code'] . ' · ' . $t['departure'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Options', 'nvf-bus-booking' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="override_capacity" value="1" id="nvf-override-capacity" />
								<?php esc_html_e( 'Override capacity (admin/team override)', 'nvf-bus-booking' ); ?>
							</label>
							<p id="nvf-override-warning" class="nvf-override-warning">
								⚠ <?php esc_html_e( 'This bypasses the seat limit. The booking will be confirmed even if the trip is full.', 'nvf-bus-booking' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save booking', 'nvf-bus-booking' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handleSubmit(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'nvf-bus-booking' ) );
		}
		check_admin_referer( 'nvf_manual_add' );

		$email    = isset( $_POST['email'] )    ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name     = isset( $_POST['name'] )     ? sanitize_text_field( wp_unslash( $_POST['name'] ) )  : '';
		$phone    = isset( $_POST['phone'] )    ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$inbound  = isset( $_POST['inbound_trip'] )  ? (int) $_POST['inbound_trip']  : 0;
		$outbound = isset( $_POST['outbound_trip'] ) ? (int) $_POST['outbound_trip'] : 0;
		$pickup   = isset( $_POST['inbound_pickup'] ) ? sanitize_key( wp_unslash( $_POST['inbound_pickup'] ) ) : '';
		$override = ! empty( $_POST['override_capacity'] );

		try {
			BookingService::createAsAdmin(
				$email, $name, $phone,
				$inbound  > 0 ? [ 'trip_id' => $inbound,  'pickup' => $pickup ] : [],
				$outbound > 0 ? [ 'trip_id' => $outbound ] : [],
				$override
			);
			$flash = 'ok';
		} catch ( \Throwable $e ) {
			$flash = preg_replace( '/[^a-z_]/i', '_', strtolower( $e->getMessage() ) );
			$flash = substr( $flash, 0, 60 );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'nvf_added' => $flash ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function trips( string $direction ): array {
		$query = new \WP_Query( [
			'post_type'      => PostTypes::TRIP,
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => 'direction', 'value' => $direction ] ],
			'orderby'        => 'meta_value',
			'meta_key'       => 'departure_datetime',
			'order'          => 'ASC',
		] );
		$out = [];
		foreach ( $query->posts as $post ) {
			$dt = (string) get_post_meta( $post->ID, 'departure_datetime', true );
			$out[] = [
				'id'        => $post->ID,
				'code'      => (string) get_post_meta( $post->ID, 'trip_code', true ),
				'departure' => self::lisbonHuman( $dt ),
			];
		}
		wp_reset_postdata();
		return $out;
	}

	private static function lisbonHuman( string $dt ): string {
		if ( $dt === '' ) {
			return '—';
		}
		$formatted = \NVF\BusBooking\Support\Time::formatHuman( $dt );
		return $formatted !== '' ? $formatted : $dt;
	}
}
