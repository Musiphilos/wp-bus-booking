<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Auth;

/**
 * Read-only adapter onto Elementor Pro's submissions tables.
 *
 * Tables (default WP prefix):
 *   wp_e_submissions         (element_id ↔ form id, status, created_at)
 *   wp_e_submissions_values  (submission_id, key, value)
 *
 * The registration form's Elementor element_id is configurable via the
 * `nvf_elementor_form_id` setting (default: 997de44 — verified on dev).
 *
 * Field keys are matched against the live form (verified 2026-05-16): the
 * email is in `email`, full name in `fullName`, phone in `phone`.
 */
final class ElementorLookup {

	private const EMAIL_FIELD = 'email';
	private const NAME_FIELD  = 'fullName';
	private const PHONE_FIELD = 'phone';

	/** @return array{email:string,name:string,phone:string}|null */
	public static function findByEmail( string $email ): ?array {
		global $wpdb;

		$normalized = strtolower( trim( $email ) );
		if ( $normalized === '' || ! is_email( $normalized ) ) {
			return null;
		}

		$formIdentifier = self::elementId();
		$submissions    = $wpdb->prefix . 'e_submissions';
		$values         = $wpdb->prefix . 'e_submissions_values';

		// Match against either `element_id` (Elementor's internal short hash) or
		// `form_name` (the human label). Whitespace is normalised on both sides
		// so "Registrations 2026" and "Registrations2026" both work.
		$compact = preg_replace( '/\s+/', '', $formIdentifier );
		$submissionId = $wpdb->get_var( $wpdb->prepare(
			"SELECT s.id
			 FROM {$submissions} s
			 INNER JOIN {$values} v ON v.submission_id = s.id
			 WHERE ( s.element_id = %s OR REPLACE(s.form_name, ' ', '') = %s )
			   AND v.`key` = %s
			   AND LOWER(v.value) = %s
			 ORDER BY s.id DESC
			 LIMIT 1",
			$formIdentifier,
			$compact,
			self::EMAIL_FIELD,
			$normalized
		) );

		if ( ! $submissionId ) {
			return null;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT `key`, value
			 FROM {$values}
			 WHERE submission_id = %d
			   AND `key` IN (%s, %s, %s)",
			(int) $submissionId,
			self::EMAIL_FIELD,
			self::NAME_FIELD,
			self::PHONE_FIELD
		) );

		$out = [ 'email' => $normalized, 'name' => '', 'phone' => '' ];
		foreach ( $rows as $r ) {
			$v = is_string( $r->value ) ? trim( $r->value ) : '';
			match ( $r->key ) {
				self::EMAIL_FIELD => $out['email'] = strtolower( $v ?: $normalized ),
				self::NAME_FIELD  => $out['name']  = $v,
				self::PHONE_FIELD => $out['phone'] = $v,
				default           => null,
			};
		}
		return $out;
	}

	public static function elementId(): string {
		$id = (string) \NVF\BusBooking\Support\Settings::get( 'nvf_elementor_form_id', '' );
		if ( $id !== '' ) {
			return $id;
		}
		return '997de44';
	}
}
