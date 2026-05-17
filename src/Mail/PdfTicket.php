<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Mail;

use Dompdf\Dompdf;
use Dompdf\Options;
use NVF\BusBooking\Support\Paths;

/**
 * Renders the booking PDF ticket and returns a path to the temp file so wp_mail
 * can attach it. Single PDF per booking, listing every active leg.
 *
 * Style is held in templates/pdf/ticket.php — keep it inline-CSS only, Dompdf
 * is conservative about stylesheets.
 */
final class PdfTicket {

	/**
	 * @return string Absolute path to the generated PDF (in the system temp dir).
	 *
	 * @throws \RuntimeException on render failure.
	 */
	public static function generate( array $context ): string {
		if ( ! class_exists( Dompdf::class ) ) {
			throw new \RuntimeException( 'Dompdf is not installed. Run composer install on the server.' );
		}

		$html = self::renderHtml( $context );

		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );

		$pdf = new Dompdf( $options );
		$pdf->loadHtml( $html, 'UTF-8' );
		$pdf->setPaper( 'A4', 'portrait' );
		$pdf->render();

		$body = $pdf->output();
		if ( ! is_string( $body ) || $body === '' ) {
			throw new \RuntimeException( 'Dompdf produced an empty PDF.' );
		}

		$dir = self::tempDir();
		$ref = isset( $context['booking_ref'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $context['booking_ref'] ) : 'ticket';
		// Suffix is base64url-safe random so directory listing can't predict names.
		$suffix = bin2hex( random_bytes( 6 ) );
		$path   = $dir . '/' . $ref . '-' . $suffix . '.pdf';
		if ( false === @file_put_contents( $path, $body ) ) {
			throw new \RuntimeException( 'Could not write PDF to plugin tickets dir.' );
		}
		return $path;
	}

	/**
	 * Plugin-scoped tickets directory under wp-content/uploads. The parent
	 * folder already has the `.htaccess` deny rule from Activator::ensureLogDir,
	 * and we add an empty index.html the first time we touch it so a
	 * mis-configured webserver still serves nothing useful on directory listing.
	 */
	private static function tempDir(): string {
		$dir = trailingslashit( Paths::uploadsDir() ) . 'tickets';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$guard = $dir . '/index.html';
		if ( ! file_exists( $guard ) ) {
			@file_put_contents( $guard, '' );
		}
		return $dir;
	}

	private static function renderHtml( array $context ): string {
		$path = NVF_BB_DIR . 'templates/pdf/ticket.php';
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( 'PDF template missing.' );
		}
		ob_start();
		extract( $context, EXTR_SKIP );
		require $path;
		return (string) ob_get_clean();
	}
}
