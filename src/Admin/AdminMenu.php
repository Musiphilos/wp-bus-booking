<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Admin;

/**
 * Top-level admin menu and shared asset enqueueing for plugin admin pages.
 */
final class AdminMenu {

	public const SLUG       = 'nvf-bus-booking';
	public const CAPABILITY = 'edit_others_posts';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_notices', [ self::class, 'dupEmailNotice' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueAssets' ] );
	}

	public static function dupEmailNotice(): void {
		$key   = 'nvf_email_dupe_' . get_current_user_id();
		$email = get_transient( $key );
		if ( ! $email ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s <code>%s</code></p></div>',
			esc_html__( 'Duplicate booking email:', 'nvf-bus-booking' ),
			esc_html__( 'another booking already exists for', 'nvf-bus-booking' ),
			esc_html( (string) $email )
		);
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Bus Booking', 'nvf-bus-booking' ),
			__( 'Bus Booking', 'nvf-bus-booking' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'renderDashboard' ],
			self::iconDataUri(),
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'nvf-bus-booking' ),
			__( 'Dashboard', 'nvf-bus-booking' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'renderDashboard' ]
		);
	}

	/**
	 * Monochrome SVG bus icon. Black fill lets WP admin colorize it
	 * automatically based on the user's admin color scheme.
	 */
	private static function iconDataUri(): string {
		$svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black">
	<path d="M4.5 2.5h11a2 2 0 0 1 2 2v9.25a1.25 1.25 0 0 1-1.25 1.25H16v.75a1.5 1.5 0 1 1-3 0V15H7v.75a1.5 1.5 0 1 1-3 0V15H3.75A1.25 1.25 0 0 1 2.5 13.75V4.5a2 2 0 0 1 2-2Zm0 1.5a.5.5 0 0 0-.5.5v5h12V4.5a.5.5 0 0 0-.5-.5h-11Zm-.5 7v2.5h12V11H4Zm1.25 1a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5Zm9.5 0a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5Z"/>
	<path d="M7 5h6v1.25H7V5Z" opacity=".55"/>
</svg>
SVG;
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public static function renderDashboard(): void {
		Dashboard::render();
	}

	public static function enqueueAssets( string $hookSuffix ): void {
		// Scope to our plugin pages only: top-level page, subpages, and our CPT list tables.
		$isOurPage = str_contains( $hookSuffix, self::SLUG )
			|| str_contains( $hookSuffix, 'nvf_booking' )
			|| str_contains( $hookSuffix, 'nvf_trip' );

		if ( ! $isOurPage ) {
			return;
		}

		$base = plugin_dir_url( dirname( __DIR__, 2 ) . '/nvf-bus-booking.php' );
		$ver  = NVF_BB_VERSION;
		wp_enqueue_style(  'nvf-admin', $base . 'assets/css/admin.css', [], $ver );
		wp_enqueue_script( 'nvf-admin', $base . 'assets/js/admin.js',  [], $ver, true );
	}
}
