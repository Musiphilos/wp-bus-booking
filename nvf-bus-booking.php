<?php
/**
 * Plugin Name:       Bus Booking
 * Plugin URI:        https://lbswing.com
 * Description:       Shuttle bus booking for LB Swing event participants. Uses Meta Box AIO for the data layer and MCP Adapter for AI/agent access.
 * Version:           0.4.2
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            LB Swing
 * License:           GPL-2.0-or-later
 * Text Domain:       nvf-bus-booking
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'NVF_BB_VERSION', '0.4.2' );
define( 'NVF_BB_FILE', __FILE__ );
define( 'NVF_BB_DIR', plugin_dir_path( __FILE__ ) );
define( 'NVF_BB_URL', plugin_dir_url( __FILE__ ) );

$nvf_bb_autoload = NVF_BB_DIR . 'vendor/autoload.php';
if ( ! is_readable( $nvf_bb_autoload ) ) {
	add_action( 'admin_notices', static function (): void {
		echo '<div class="notice notice-error"><p><strong>NVF Bus Booking:</strong> <code>vendor/autoload.php</code> is missing. Run <code>composer install</code> on the server.</p></div>';
	} );
	return;
}
require_once $nvf_bb_autoload;

register_activation_hook( __FILE__, [ \NVF\BusBooking\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \NVF\BusBooking\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ \NVF\BusBooking\Plugin::class, 'boot' ] );
