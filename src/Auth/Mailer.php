<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Auth;

use NVF\BusBooking\Mail\Mailer as MailMailer;

/**
 * Back-compat shim. The real Mailer moved to NVF\BusBooking\Mail\Mailer in M5.
 * Kept here so existing imports (Auth\MagicLinkController, Admin\SettingsPage)
 * compile without churn.
 */
final class Mailer {

	public static function sendMagicLink( string $to, string $url, int $ttlHours ): bool {
		return MailMailer::sendMagicLink( $to, $url, $ttlHours );
	}

	public static function sendTest( string $to ): array {
		return MailMailer::sendTest( $to );
	}
}
