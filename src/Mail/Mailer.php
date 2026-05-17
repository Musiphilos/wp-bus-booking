<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Mail;

use NVF\BusBooking\Support\Logger;
use NVF\BusBooking\Support\StringRenderer;

/**
 * All transactional email for the plugin. Backed by wp_mail() so the host's
 * configured SMTP/API plugin (WP Mail SMTP → Brevo on dev) does the heavy lifting.
 *
 * Each public method builds context, renders {template}.html.php + {template}.txt.php,
 * attaches a PDF where applicable, and sends. Every send is wrapped in PHPMailer
 * diagnostics that get logged at INFO on success / ERROR on failure so the operator
 * can audit delivery from the Debug Log page.
 */
final class Mailer {

	/** @var array<string,mixed> */
	private static array $lastDiagnostics = [];

	// ---- Public API ---------------------------------------------------------

	public static function sendMagicLink( string $to, string $url, int $ttlHours ): bool {
		$subject = StringRenderer::renderPlain( 'email.magic_link.subject', [ 'ttl_hours' => $ttlHours ] );
		return self::send( $to, $subject, 'magic-link', [ 'url' => $url, 'ttlHours' => $ttlHours ] );
	}

	public static function sendConfirmation( string $to, array $context ): bool {
		$subject = StringRenderer::renderPlain( 'email.confirmation.subject', $context );
		$pdf     = null;
		try {
			$pdf = PdfTicket::generate( $context );
		} catch ( \Throwable $e ) {
			Logger::error( 'mail.pdf_failed', [ 'reason' => $e->getMessage() ] );
		}
		return self::send( $to, $subject, 'confirmation', $context, $pdf ? [ $pdf ] : [] );
	}

	public static function sendCancellation( string $to, array $context ): bool {
		$subject = StringRenderer::renderPlain( 'email.cancellation.subject', $context );
		return self::send( $to, $subject, 'cancellation', $context );
	}

	public static function sendSpotOpened( string $to, array $context ): bool {
		$subject = StringRenderer::renderPlain( 'email.spot_opened.subject', $context );
		return self::send( $to, $subject, 'spot-opened', $context );
	}

	public static function sendSpotTaken( string $to, array $context ): bool {
		$subject = StringRenderer::renderPlain( 'email.spot_taken.subject', $context );
		return self::send( $to, $subject, 'spot-taken', $context );
	}

	public static function sendAdminNotification( string $event, array $context ): bool {
		$recipients = self::adminRecipients();
		if ( empty( $recipients ) ) {
			Logger::warning( 'mail.admin_notification.no_recipients' );
			return false;
		}
		$context['admin_event'] = $event;
		$subject = StringRenderer::renderPlain( 'email.admin_notification.subject', $context );
		return self::send( implode( ',', $recipients ), $subject, 'admin-notification', $context );
	}

	/** Live deliverability probe wired to the Debug Log button. */
	public static function sendTest( string $to ): array {
		self::$lastDiagnostics = [];
		self::attachDiagnostics();

		$ok = wp_mail(
			$to,
			'NVF Bus Booking — test email',
			'<p>This is a delivery test from the NVF Bus Booking plugin. Timestamp: ' . esc_html( gmdate( 'c' ) ) . '</p>',
			self::headers()
		);
		$result = array_merge( [ 'ok' => (bool) $ok, 'to' => self::maskEmail( $to ) ], self::$lastDiagnostics );
		Logger::log( $ok ? Logger::INFO : Logger::ERROR, 'mailer.test', $result );
		self::detachDiagnostics();
		return $result;
	}

	// ---- Internals ---------------------------------------------------------

	private static function send( string $to, string $subject, string $template, array $context, array $attachments = [] ): bool {
		self::$lastDiagnostics = [];
		self::attachDiagnostics();

		$html = self::render( $template, 'html', $context );
		$text = self::render( $template, 'txt',  $context );

		// We pass plain-text body to wp_mail but inject the HTML version via
		// `phpmailer_init` so the message is true multipart/alternative.
		$plain = $text !== '' ? $text : wp_strip_all_tags( $html );
		$htmlBody = $html;

		$bodyInjector = static function ( \PHPMailer\PHPMailer\PHPMailer $mailer ) use ( $htmlBody ): void {
			if ( $htmlBody !== '' ) {
				$mailer->isHTML( true );
				$mailer->AltBody = $mailer->Body;
				$mailer->Body    = $htmlBody;
			}
		};
		add_action( 'phpmailer_init', $bodyInjector );

		Logger::info( 'mail.attempt', [
			'to'        => self::maskList( $to ),
			'subject'   => $subject,
			'template'  => $template,
			'has_pdf'   => ! empty( $attachments ),
		] );

		$ok = wp_mail( $to, $subject, $plain, self::headers(), $attachments );

		remove_action( 'phpmailer_init', $bodyInjector );

		Logger::log(
			$ok ? Logger::INFO : Logger::ERROR,
			$ok ? 'mail.sent' : 'mail.failed',
			array_merge(
				[ 'to' => self::maskList( $to ), 'template' => $template, 'wp_mail_return' => $ok ],
				self::$lastDiagnostics
			)
		);
		self::detachDiagnostics();

		// Clean up the temporary PDF file once wp_mail has copied it into the MIME body.
		// Both legacy (sys_get_temp_dir) and current (wp-content/uploads/nvf-bus-booking/tickets) paths are honoured.
		$ticketDir = trailingslashit( \NVF\BusBooking\Support\Paths::uploadsDir() ) . 'tickets';
		foreach ( $attachments as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			if ( str_starts_with( $path, sys_get_temp_dir() ) || str_starts_with( $path, $ticketDir ) ) {
				@unlink( $path );
			}
		}

		return (bool) $ok;
	}

	private static function render( string $template, string $type, array $context ): string {
		$path = NVF_BB_DIR . 'templates/email/' . $template . '.' . $type . '.php';
		if ( ! is_readable( $path ) ) {
			// Fallback to magic-link's original location for back-compat with M2 template.
			if ( $template === 'magic-link' ) {
				$path = NVF_BB_DIR . 'templates/email/magic-link.php';
				if ( ! is_readable( $path ) ) {
					return '';
				}
				return self::evalTemplate( $path, $context );
			}
			return '';
		}
		return self::evalTemplate( $path, $context );
	}

	private static function evalTemplate( string $path, array $context ): string {
		ob_start();
		extract( $context, EXTR_SKIP );
		require $path;
		return (string) ob_get_clean();
	}

	/** @return array<int,string> */
	private static function headers(): array {
		$name = (string) \NVF\BusBooking\Support\Settings::get( 'nvf_email_sender_name', '' );
		$from = (string) \NVF\BusBooking\Support\Settings::get( 'nvf_email_sender_address', '' );
		$h    = [ 'Content-Type: text/plain; charset=UTF-8' ];
		if ( $from !== '' ) {
			$h[] = sprintf( 'From: %s <%s>', $name !== '' ? $name : 'LB Swing', $from );
		}
		return $h;
	}

	/** @return array<int,string> */
	private static function adminRecipients(): array {
		$raw = \NVF\BusBooking\Support\Settings::get( 'nvf_admin_notification_recipients', [] );
		if ( is_string( $raw ) ) {
			$raw = [ $raw ];
		}
		$out = [];
		foreach ( (array) $raw as $addr ) {
			$addr = sanitize_email( (string) $addr );
			if ( $addr ) {
				$out[] = $addr;
			}
		}
		return $out;
	}

	// ---- Diagnostics -------------------------------------------------------

	private static function attachDiagnostics(): void {
		add_action( 'phpmailer_init',  [ self::class, 'capturePhpMailer' ] );
		add_action( 'wp_mail_failed',  [ self::class, 'captureFailure' ] );
	}

	private static function detachDiagnostics(): void {
		remove_action( 'phpmailer_init', [ self::class, 'capturePhpMailer' ] );
		remove_action( 'wp_mail_failed', [ self::class, 'captureFailure' ] );
	}

	public static function capturePhpMailer( \PHPMailer\PHPMailer\PHPMailer $mailer ): void {
		self::$lastDiagnostics['envelope_from'] = $mailer->From;
		self::$lastDiagnostics['mailer_driver'] = $mailer->Mailer;
		if ( $mailer->Mailer === 'smtp' ) {
			self::$lastDiagnostics['smtp_host'] = $mailer->Host;
			self::$lastDiagnostics['smtp_port'] = (int) $mailer->Port;
		}
	}

	public static function captureFailure( \WP_Error $error ): void {
		self::$lastDiagnostics['error_code']    = $error->get_error_code();
		self::$lastDiagnostics['error_message'] = $error->get_error_message();
	}

	private static function maskEmail( string $email ): string {
		$at = strrpos( $email, '@' );
		return $at !== false && $at >= 2
			? substr( $email, 0, 1 ) . '***' . substr( $email, $at )
			: '***';
	}

	private static function maskList( string $csv ): string {
		return implode( ',', array_map( [ self::class, 'maskEmail' ], array_map( 'trim', explode( ',', $csv ) ) ) );
	}
}
