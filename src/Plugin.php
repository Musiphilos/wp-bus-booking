<?php

declare( strict_types=1 );

namespace NVF\BusBooking;

use NVF\BusBooking\Admin\AdminMenu;
use NVF\BusBooking\Admin\BookingColumns;
use NVF\BusBooking\Admin\ManifestPage;
use NVF\BusBooking\Admin\ManualAddPage;
use NVF\BusBooking\Admin\SettingsPage;
use NVF\BusBooking\Admin\TripColumns;
use NVF\BusBooking\Auth\MagicLinkController;
use NVF\BusBooking\Booking\BookingController;
use NVF\BusBooking\Booking\ClaimController;
use NVF\BusBooking\Cli\PurgeCommand;
use NVF\BusBooking\Cli\SeedTripsCommand;
use NVF\BusBooking\Cron\RetentionPurge;
use NVF\BusBooking\Domain\EmailUniqueness;
use NVF\BusBooking\Domain\MetaBoxes;
use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Mcp\Abilities;
use NVF\BusBooking\Rest\DebugController;
use NVF\BusBooking\Rest\PublicAssets;
use NVF\BusBooking\Support\Activator;
use NVF\BusBooking\Support\Logger;

/**
 * Central bootstrap. Wires every subsystem into the WordPress lifecycle.
 *
 * Kept intentionally thin: this class only constructs collaborators and
 * registers their hooks. All real behaviour lives in the subsystem classes.
 */
final class Plugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Domain.
		PostTypes::register();
		MetaBoxes::register();
		EmailUniqueness::register();

		// MCP / Abilities API. Dev-only — the src/Mcp/ directory is excluded
		// from release builds (see Makefile), so the class is absent on
		// distributed installs and registration is silently skipped.
		if ( class_exists( Abilities::class ) ) {
			Abilities::register();
		}

		// Shortcode + frontend assets (booking page placeholder + debug panel).
		PublicAssets::register();

		// REST.
		DebugController::register();
		MagicLinkController::register();
		BookingController::register();
		ClaimController::register();

		// Admin.
		AdminMenu::register();
		SettingsPage::register();
		TripColumns::register();
		BookingColumns::register();
		ManifestPage::register();
		ManualAddPage::register();

		// Cron.
		RetentionPurge::register();

		// CLI.
		SeedTripsCommand::register();
		PurgeCommand::register();

		// Boot is high-frequency noise (polls, REST, admin-ajax) — keep at debug level.
		Logger::debug( 'plugin.boot', [ 'version' => NVF_BB_VERSION ] );
	}

	public static function activate(): void {
		Activator::run();
		Logger::info( 'plugin.activate', [ 'version' => NVF_BB_VERSION ] );
	}

	public static function deactivate(): void {
		Activator::tearDown();
		Logger::info( 'plugin.deactivate', [ 'version' => NVF_BB_VERSION ] );
	}
}
