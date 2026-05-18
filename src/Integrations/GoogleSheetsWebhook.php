<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Integrations;

use NVF\BusBooking\Mail\BookingContext;
use NVF\BusBooking\Support\Logger;
use NVF\BusBooking\Support\Time;

/**
 * Push booking events to a Google Apps Script web app, which appends a row
 * to a sheet. Optional — only fires when `nvf_google_sheets_webhook_url` is set.
 *
 * Apps Script example to paste into the sheet (Extensions → Apps Script):
 *
 *   function doPost(e) {
 *     const payload = JSON.parse(e.postData.contents);
 *     const sheet   = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('Bookings');
 *     if (!sheet) return ContentService.createTextOutput('no sheet');
 *     sheet.appendRow([
 *       payload.received_at, payload.event, payload.booking_ref,
 *       payload.participant_name, payload.participant_email, payload.participant_phone,
 *       payload.inbound_code, payload.inbound_status, payload.inbound_pickup,
 *       payload.outbound_code, payload.outbound_status,
 *       payload.price_eur, payload.price_label,
 *     ]);
 *     return ContentService.createTextOutput('ok');
 *   }
 *
 *   Deploy → New deployment → Web app → Execute as: Me · Access: Anyone.
 *   Paste the resulting /exec URL into the plugin settings.
 *
 * Failure mode: a Sheets push problem never breaks the booking write — we
 * log at ERROR and move on.
 */
final class GoogleSheetsWebhook {

	public const OPTION_KEY = 'nvf_google_sheets_webhook_url';

	public static function dispatch( string $event, int $bookingId ): void {
		$url = trim( (string) \NVF\BusBooking\Support\Settings::get( self::OPTION_KEY, '' ) );
		if ( $url === '' ) {
			Logger::debug( 'sheets.no_url', [ 'event' => $event ] );
			return;
		}
		Logger::info( 'sheets.dispatch_start', [ 'event' => $event, 'booking_id' => $bookingId ] );
		if ( ! wp_http_validate_url( $url ) ) {
			Logger::warning( 'sheets.invalid_url', [ 'url' => $url ] );
			return;
		}

		try {
			$ctx = BookingContext::build( $bookingId );
		} catch ( \Throwable $e ) {
			Logger::error( 'sheets.context_failed', [ 'booking_id' => $bookingId, 'reason' => $e->getMessage() ] );
			return;
		}

		$payload = self::flatten( $event, $ctx );

		// Apps Script's HTTP frontend only routes application/x-www-form-urlencoded
		// cleanly to /exec; application/json and text/plain trigger redirects to
		// script.googleusercontent.com that downgrade POST→GET → 405. We send the
		// payload as a single `payload` form field whose value is JSON. The Apps
		// Script then reads JSON.parse(e.parameter.payload).
		$response = wp_remote_post( $url, [
			'method'      => 'POST',
			'timeout'     => 8,
			'redirection' => 5,
			'body'        => [ 'payload' => wp_json_encode( $payload ) ],
			'blocking'    => true,
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'sheets.push_failed', [ 'reason' => $response->get_error_message() ] );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			Logger::error( 'sheets.push_non_2xx', [ 'status' => $code, 'body' => substr( $body, 0, 200 ) ] );
			return;
		}
		// Apps Script returns 200 even when the script throws — the failure mode
		// shows up as an Error HTML page in the body. The doPost we ship returns
		// ContentService.createTextOutput('ok' | 'ok appended' | 'ok upserted ...').
		// Anything that doesn't begin with "ok" is a soft-failure worth surfacing.
		$trimmed = ltrim( $body );
		if ( stripos( $trimmed, 'ok' ) !== 0 ) {
			Logger::error( 'sheets.push_soft_fail', [
				'status'      => $code,
				'body_prefix' => substr( $trimmed, 0, 200 ),
				'event'       => $event,
				'booking_ref' => $payload['booking_ref'],
			] );
			return;
		}
		Logger::info( 'sheets.pushed', [
			'event'       => $event,
			'booking_ref' => $payload['booking_ref'],
			'response'    => substr( $trimmed, 0, 60 ),
		] );
	}

	/**
	 * Flatten the BookingContext into a single shallow array so the Apps Script
	 * can map columns 1:1 without parsing nested JSON.
	 */
	private static function flatten( string $event, array $ctx ): array {
		$legs = $ctx['legs'] ?? [];
		$byDir = [ 'inbound' => null, 'outbound' => null ];
		foreach ( $legs as $leg ) {
			$byDir[ $leg['direction'] ] = $leg;
		}
		$inbound  = $byDir['inbound']  ?? [];
		$outbound = $byDir['outbound'] ?? [];

		return [
			'received_at'        => Time::nowIso(),
			'event'              => $event,
			'booking_ref'        => $ctx['booking_ref'] ?? '',
			'participant_name'   => $ctx['participant_name']  ?? '',
			'participant_email'  => $ctx['participant_email'] ?? '',
			'participant_phone'  => $ctx['participant_phone'] ?? '',
			'inbound_code'       => $inbound['trip_code']       ?? '',
			'inbound_departure'  => $inbound['departure_human'] ?? '',
			'inbound_status'     => $inbound['status']          ?? '',
			'inbound_pickup'     => $inbound['pickup_label']    ?? '',
			'outbound_code'      => $outbound['trip_code']       ?? '',
			'outbound_departure' => $outbound['departure_human'] ?? '',
			'outbound_status'    => $outbound['status']          ?? '',
			'price_eur'          => $ctx['price_eur']   ?? '',
			'price_label'        => $ctx['price_label'] ?? '',
		];
	}
}
