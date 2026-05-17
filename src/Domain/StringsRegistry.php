<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Domain;

/**
 * Single source of truth for every editorial string that an admin can override
 * via the (forthcoming) Text & Copy settings page.
 *
 * Each entry declares:
 *  - id          : stable key (dot-namespaced).
 *  - label       : short admin-UI label.
 *  - description : help text for the admin (one sentence).
 *  - default     : the canonical English string, wrapped in __() so Poedit can
 *                  still extract translations and so existing installs keep
 *                  rendering today's wording verbatim until someone edits.
 *  - tokens      : whitelist of {{token}} names this field may contain. The
 *                  renderer logs a warning when an unknown token is seen.
 *  - type        : 'text' (caller escapes) | 'textarea' (multi-line plain) |
 *                  'html' (admin may write HTML; tokens are escaped at substitution time).
 *  - group       : settings UI grouping bucket.
 *
 * The registry is *additive*: new entries can be added without breaking existing
 * stored values. Defaults are returned whenever the stored value is empty.
 */
final class StringsRegistry {

	/**
	 * @return array<int,array{id:string,label:string,description:string,default:string,tokens:array<int,string>,type:string,group:string}>
	 */
	public static function declarations(): array {
		// Tokens available almost everywhere (booking-context emails + PDF).
		$bookingTokens = [
			'participant_name', 'participant_email', 'participant_phone',
			'booking_ref',
			'inbound_code', 'inbound_departure', 'inbound_status', 'inbound_pickup',
			'outbound_code', 'outbound_departure', 'outbound_status',
			'price_eur', 'price_label',
			'cancellation_deadline',
			'contact_email', 'portal_url',
		];
		$tripTokens   = [ 'trip_code', 'trip_departure', 'participant_name', 'direction', 'contact_email', 'portal_url' ];
		$claimTokens  = array_merge( $tripTokens, [ 'claim_url', 'ttl_hours', 'booking_ref' ] );

		return [
			// ─── Booking page ──────────────────────────────────────────────
			self::entry( 'booking.eyebrow', 'Eyebrow',
				'Small uppercase line above the page heading.',
				__( 'LB Swing Shuttle', 'nvf-bus-booking' ),
				[], 'text', 'booking_page' ),

			self::entry( 'booking.h1', 'Page heading (H1)',
				'Main page heading shown above the form.',
				__( 'Manage your shuttle seat', 'nvf-bus-booking' ),
				[], 'text', 'booking_page' ),

			self::entry( 'booking.trip_strip.dates', 'Trip strip — dates',
				'Date band in the ticket-style strip under the heading.',
				__( 'Sep 24 — 28', 'nvf-bus-booking' ),
				[], 'text', 'booking_page' ),

			self::entry( 'booking.trip_strip.origin', 'Trip strip — origin city',
				'Origin label in the ticket strip.',
				__( 'Porto', 'nvf-bus-booking' ),
				[], 'text', 'booking_page' ),

			self::entry( 'booking.trip_strip.destination', 'Trip strip — destination',
				'Destination label in the ticket strip.',
				__( 'Grande Hotel Thermas', 'nvf-bus-booking' ),
				[], 'text', 'booking_page' ),

			self::entry( 'booking.lede', 'Lede paragraph',
				'Short editorial paragraph between the trip strip and the form card.',
				__( 'Two arrivals on Sep 24 and two returns on Sep 28 between Porto and the Grande Hotel Thermas. Book your seat — pay the driver in cash on the day.', 'nvf-bus-booking' ),
				[], 'textarea', 'booking_page' ),

			self::entry( 'booking.step1.heading', 'Step 1 · card heading',
				'Heading inside the email-entry card.',
				__( 'Register or cancel your shuttle booking', 'nvf-bus-booking' ),
				[], 'text', 'booking_page' ),

			self::entry( 'booking.step1.intro', 'Step 1 · intro copy',
				'Explainer paragraph above the email input. {{ttl_hours}} reads from Settings → Magic-link expiry.',
				__( 'Use the same email you used to register for the event. We will send you a single-use sign-in link — no password needed. The link is valid for {{ttl_hours}} hours.', 'nvf-bus-booking' ),
				[ 'ttl_hours' ], 'textarea', 'booking_page' ),

			self::entry( 'booking.step1.footnote', 'Step 1 · footnote',
				'Help line under the submit button. {{contact_email}} reads from Settings → Email.',
				/* translators: %s = contact email token */
				__( 'If you have not received the link in a few minutes, check your spam folder or contact us at {{contact_email}}.', 'nvf-bus-booking' ),
				[ 'contact_email' ], 'textarea', 'booking_page' ),

			self::entry( 'booking.gdpr_label', 'GDPR consent label',
				'Text next to the GDPR checkbox in Step 2.',
				__( 'I consent to the storage of my booking details for the duration of the event. Cash payment is collected on the bus.', 'nvf-bus-booking' ),
				[], 'textarea', 'booking_page' ),

			self::entry( 'booking.includes.1', 'Includes bullet 1',
				'First bullet in the 2×2 "What\'s included" grid. Save blank to hide; click Reset to default to bring it back.',
				__( 'Free to reserve · cash on board', 'nvf-bus-booking' ),
				[], 'text', 'booking_page', [ 'blank_hides' => true ] ),
			self::entry( 'booking.includes.2', 'Includes bullet 2',
				'Second bullet in the includes grid. Save blank to hide.',
				__( 'One seat per participant', 'nvf-bus-booking' ),
				[], 'text', 'booking_page', [ 'blank_hides' => true ] ),
			self::entry( 'booking.includes.3', 'Includes bullet 3',
				'Third bullet in the includes grid. Save blank to hide.',
				__( 'Cancel anytime before the event', 'nvf-bus-booking' ),
				[], 'text', 'booking_page', [ 'blank_hides' => true ] ),
			self::entry( 'booking.includes.4', 'Includes bullet 4',
				'Fourth bullet in the includes grid. Save blank to hide.',
				__( 'Pickup at Airport or Casa da Música', 'nvf-bus-booking' ),
				[], 'text', 'booking_page', [ 'blank_hides' => true ] ),

			self::entry( 'booking.help_line', 'Help line · Your-booking screen',
				'Line shown under the booking summary view.',
				__( 'Need help? Email us at {{contact_email}} — please include your booking name and direction.', 'nvf-bus-booking' ),
				[ 'contact_email' ], 'textarea', 'booking_page' ),

			// ─── Email · Magic link ───────────────────────────────────────
			self::entry( 'email.magic_link.subject', 'Subject',
				'Subject line of the sign-in link email.',
				__( 'Your LB Swing shuttle booking link', 'nvf-bus-booking' ),
				[], 'text', 'email_magic_link' ),

			self::entry( 'email.magic_link.body', 'Body paragraph',
				'Main sentence above the CTA button. {{ttl_hours}} is the link lifetime in hours.',
				__( 'Use the button below to open your booking page. The link is valid once and expires in {{ttl_hours}} hours.', 'nvf-bus-booking' ),
				[ 'ttl_hours' ], 'textarea', 'email_magic_link' ),

			// ─── Email · Booking confirmation ─────────────────────────────
			self::entry( 'email.confirmation.subject', 'Subject',
				'Confirmation email subject. Common tokens: {{booking_ref}}.',
				__( 'Your shuttle booking · {{booking_ref}}', 'nvf-bus-booking' ),
				[ 'booking_ref' ], 'text', 'email_confirmation' ),

			self::entry( 'email.confirmation.greeting', 'Greeting',
				'Opening sentence of the body. HTML allowed (<strong>, <em>, links).',
				__( 'Hi {{participant_name}} — your booking <strong>{{booking_ref}}</strong> is on file. Here are the details:', 'nvf-bus-booking' ),
				[ 'participant_name', 'booking_ref' ], 'html', 'email_confirmation' ),

			self::entry( 'email.confirmation.on_the_day', 'On-the-day paragraph',
				'Cash-on-board reminder block below the leg cards. HTML allowed.',
				__( '<strong>On the day:</strong> please be at your pickup point 10 minutes early. Fare is <strong>{{price_eur}} €</strong> ({{price_label}}), payable in cash to the driver.', 'nvf-bus-booking' ),
				[ 'price_eur', 'price_label' ], 'html', 'email_confirmation' ),

			self::entry( 'email.confirmation.cancellation_reminder', 'Cancellation reminder',
				'Optional reminder paragraph mentioning the cancellation deadline. HTML allowed.',
				__( '<strong>Need to cancel?</strong> Free until {{cancellation_deadline}}. Use the <a href="{{portal_url}}">booking page</a> or email {{contact_email}}.', 'nvf-bus-booking' ),
				[ 'cancellation_deadline', 'contact_email', 'portal_url' ], 'html', 'email_confirmation' ),

			self::entry( 'email.confirmation.attachment_note', 'Attachment note',
				'Small line about the PDF attachment.',
				__( 'A printable PDF ticket is attached. Show it (or the booking reference) to the team when boarding.', 'nvf-bus-booking' ),
				[], 'textarea', 'email_confirmation' ),

			// ─── Email · Cancellation ─────────────────────────────────────
			self::entry( 'email.cancellation.subject', 'Subject',
				'Cancellation email subject.',
				__( 'Shuttle booking cancelled · {{booking_ref}}', 'nvf-bus-booking' ),
				[ 'booking_ref' ], 'text', 'email_cancellation' ),

			self::entry( 'email.cancellation.greeting', 'Greeting',
				'Opening sentence of the cancellation body. HTML allowed.',
				__( 'Hi {{participant_name}} — your booking <strong>{{booking_ref}}</strong> has been updated. Current status:', 'nvf-bus-booking' ),
				[ 'participant_name', 'booking_ref' ], 'html', 'email_cancellation' ),

			self::entry( 'email.cancellation.rebook_line', 'Rebook line',
				'Closing line nudging the participant to rebook if they want. HTML allowed.',
				__( 'Need to rebook? Visit the <a href="{{portal_url}}">booking page</a> and use the same email — your registration is still recognised.', 'nvf-bus-booking' ),
				[ 'portal_url' ], 'html', 'email_cancellation' ),

			// ─── Email · Admin notification ───────────────────────────────
			self::entry( 'email.admin_notification.subject', 'Subject template',
				'Subject of the admin notification. {{admin_event}} is the event name (e.g. booking.created).',
				__( '[NVF] {{admin_event}} · {{booking_ref}}', 'nvf-bus-booking' ),
				[ 'admin_event', 'booking_ref' ], 'text', 'email_admin' ),

			self::entry( 'email.admin_notification.intro', 'Intro line',
				'Optional line above the data table (leave blank to hide).',
				'',
				[ 'admin_event', 'booking_ref' ], 'textarea', 'email_admin' ),

			// ─── Email · Waitlist · Spot opened ───────────────────────────
			self::entry( 'email.spot_opened.subject', 'Subject',
				'Subject of the "spot opened" notification.',
				__( 'Seat available · {{trip_code}} — first to confirm wins', 'nvf-bus-booking' ),
				[ 'trip_code' ], 'text', 'email_spot_opened' ),

			self::entry( 'email.spot_opened.greeting', 'Greeting',
				'Opening sentence telling the waitlister a seat is open. HTML allowed.',
				__( 'Hi {{participant_name}} — a seat just freed up on <strong>{{trip_code}}</strong> ({{direction}}).', 'nvf-bus-booking' ),
				[ 'participant_name', 'trip_code', 'direction' ], 'html', 'email_spot_opened' ),

			self::entry( 'email.spot_opened.lead', 'First-to-confirm paragraph',
				'Paragraph that sets the FCFS expectation. HTML allowed.',
				__( '<strong>First to confirm wins.</strong> Everyone currently on the waiting list received this email at the same time — click the button below to claim it. The link is valid for {{ttl_hours}} hours.', 'nvf-bus-booking' ),
				[ 'ttl_hours' ], 'html', 'email_spot_opened' ),

			self::entry( 'email.spot_opened.cta', 'CTA button label',
				'Text of the big primary button.',
				__( 'Confirm my seat', 'nvf-bus-booking' ),
				[], 'text', 'email_spot_opened' ),

			// ─── Email · Waitlist · Spot taken ────────────────────────────
			self::entry( 'email.spot_taken.subject', 'Subject',
				'Subject of the "you didn\'t get it this time" email.',
				__( 'Seat already taken · {{trip_code}} — you remain on the list', 'nvf-bus-booking' ),
				[ 'trip_code' ], 'text', 'email_spot_taken' ),

			self::entry( 'email.spot_taken.lead', 'Lead sentence',
				'Telling the participant the seat was claimed by someone else. HTML allowed.',
				__( 'Someone else just claimed the seat that opened on <strong>{{trip_code}}</strong>.', 'nvf-bus-booking' ),
				[ 'trip_code' ], 'html', 'email_spot_taken' ),

			self::entry( 'email.spot_taken.closing', 'Closing reassurance',
				'Reassurance line — they still have their place on the list. HTML allowed.',
				__( 'You remain on the waiting list <strong>in your current position</strong>. We\'ll email you the moment the next seat frees up.', 'nvf-bus-booking' ),
				[], 'html', 'email_spot_taken' ),

			// ─── PDF ticket ───────────────────────────────────────────────
			self::entry( 'pdf.passenger_label', 'Passenger label',
				'"Passenger" header on the printed ticket.',
				__( 'Passenger', 'nvf-bus-booking' ),
				[], 'text', 'pdf' ),

			self::entry( 'pdf.on_the_day', 'On-the-day instructions',
				'Footer block instructing the participant on day-of behaviour.',
				__( 'On the day: please be at your pickup point 10 minutes early. Bring cash for the driver — the fare is collected on the bus. Show this booking reference to the team.', 'nvf-bus-booking' ),
				[], 'textarea', 'pdf' ),

			self::entry( 'pdf.cancellation_reminder', 'Cancellation reminder',
				'Conditional line shown when a cancellation deadline is configured.',
				__( 'Need to cancel? Free until {{cancellation_deadline}}. After that, contact {{contact_email}}.', 'nvf-bus-booking' ),
				[ 'cancellation_deadline', 'contact_email' ], 'textarea', 'pdf' ),
		];
	}

	/** Look up one entry by id, or null. */
	public static function find( string $id ): ?array {
		foreach ( self::declarations() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/** @return array<string,array<int,array>> grouped by `group`. */
	public static function byGroup(): array {
		$out = [];
		foreach ( self::declarations() as $entry ) {
			$out[ $entry['group'] ][] = $entry;
		}
		return $out;
	}

	/**
	 * @param array<int,string> $tokens
	 * @param array<string,bool> $flags currently supports:
	 *        - blank_hides: an empty saved override is preserved (not reset to
	 *          default) so the caller can detect "admin wants this hidden".
	 */
	private static function entry( string $id, string $label, string $description, string $default, array $tokens, string $type, string $group, array $flags = [] ): array {
		return [
			'id'          => $id,
			'label'       => $label,
			'description' => $description,
			'default'     => $default,
			'tokens'      => $tokens,
			'type'        => $type,
			'group'       => $group,
			'blank_hides' => ! empty( $flags['blank_hides'] ),
		];
	}
}
