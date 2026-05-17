<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Support;

/**
 * Snapshot of the current request used by the frontend debug panel
 * and the admin Debug Log tab.
 */
final class DebugContext {

	public static function snapshot( int $logLines = 50 ): array {
		return [
			'generated_at'  => gmdate( 'c' ),
			'plugin'        => [
				'version' => defined( 'NVF_BB_VERSION' ) ? NVF_BB_VERSION : 'unknown',
				'php'     => PHP_VERSION,
				'wp'      => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : null,
			],
			'request'       => self::request(),
			'session'       => self::session(),
			'environment'   => self::environment(),
			'recent_log'    => Logger::recent( $logLines ),
		];
	}

	private static function request(): array {
		return [
			'host'   => $_SERVER['HTTP_HOST'] ?? null,
			'uri'    => $_SERVER['REQUEST_URI'] ?? null,
			'method' => $_SERVER['REQUEST_METHOD'] ?? null,
			'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
			'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
		];
	}

	private static function session(): array {
		$nvf = \NVF\BusBooking\Auth\SessionCookie::read();
		return [
			'wp_user_id'        => get_current_user_id() ?: null,
			'wp_user_email'     => wp_get_current_user()->user_email ?? null,
			'nvf_session_email' => $nvf ? $nvf['email'] : null,
		];
	}

	private static function environment(): array {
		return [
			'wp_debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'   => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'is_multisite'   => is_multisite(),
			'metabox'        => defined( 'RWMB_VER' ) ? RWMB_VER : null,
			'elementor_pro'  => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'mcp_adapter'    => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
			'event_date'     => Settings::get( 'nvf_event_start_date' ) ?: null,
			'log_file'       => Paths::logFile(),
			'log_file_size'  => file_exists( Paths::logFile() ) ? filesize( Paths::logFile() ) : 0,
		];
	}
}
