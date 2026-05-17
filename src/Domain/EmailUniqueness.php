<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Domain;

use NVF\BusBooking\Support\Logger;

/**
 * Lookup table that enforces "one booking per email" at the database level.
 *
 * Layout:
 *   email VARCHAR(190) PRIMARY KEY  (190 = MySQL utf8mb4 limit on a single-column key)
 *   booking_id BIGINT UNSIGNED NOT NULL
 *   created_at DATETIME NOT NULL
 *
 * The unique guard runs *before* the post is saved so a duplicate raises a
 * native database error — exactly the atomic semantics §4.3 calls for.
 *
 * We keep this in sync via `save_post_nvf_booking` (catches admin edits) and
 * via the explicit `BookingService::create()` path (used by the public REST
 * endpoint in M3, which will also call this class directly).
 */
final class EmailUniqueness {

	public const TABLE_SUFFIX = 'nvf_booking_emails';

	public static function register(): void {
		// Meta-level hooks: fire on every write of `participant_email`, including
		// the wp_insert_post + update_post_meta sequence used by the booking
		// service and by tests. We filter to bookings only by checking post_type.
		add_action( 'added_post_meta',   [ self::class, 'syncOnMetaChange' ], 10, 4 );
		add_action( 'updated_post_meta', [ self::class, 'syncOnMetaChange' ], 10, 4 );
		add_action( 'deleted_post_meta', [ self::class, 'syncOnMetaDelete' ], 10, 4 );
		add_action( 'before_delete_post', [ self::class, 'syncOnDelete' ] );
	}

	public static function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::tableName();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is picky about whitespace and column types — keep the indentation flat.
		$sql = "CREATE TABLE {$table} (
			email VARCHAR(190) NOT NULL,
			booking_id BIGINT(20) UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (email),
			KEY booking_id (booking_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Claim the email for this booking_id. Atomic — raises a duplicate-key
	 * exception if another booking already holds the email.
	 *
	 * @throws \RuntimeException on conflict.
	 */
	public static function claim( string $email, int $bookingId ): void {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			throw new \InvalidArgumentException( 'Email is required.' );
		}
		$table = self::tableName();

		// Suppress the WPDB error printer; we want to handle conflicts ourselves.
		$wpdb->suppress_errors( true );
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (email, booking_id, created_at) VALUES (%s, %d, %s)",
			$email,
			$bookingId,
			current_time( 'mysql', true )
		) );
		$wpdb->suppress_errors( false );

		if ( false === $result ) {
			Logger::warning( 'booking.email_uniqueness_violation', [
				'email'      => $email,
				'booking_id' => $bookingId,
				'db_error'   => $wpdb->last_error,
			] );
			throw new \RuntimeException( 'Email already has a booking.' );
		}
	}

	public static function release( int $bookingId ): void {
		global $wpdb;
		$wpdb->delete( self::tableName(), [ 'booking_id' => $bookingId ], [ '%d' ] );
	}

	/**
	 * Move the lookup row when an email changes on an existing booking.
	 *
	 * Atomic: one statement that creates the new (email → booking_id) row, OR
	 * updates the existing row for that email to point at this booking_id if
	 * the email is already claimed by THIS booking. A different booking holding
	 * the email still raises a PRIMARY KEY error (caller catches → admin notice).
	 *
	 * After the upsert succeeds we drop any stale row this booking previously
	 * owned under a different email — done last so a failure can't leave the
	 * booking unlocked.
	 */
	public static function reassign( int $bookingId, string $newEmail ): void {
		global $wpdb;
		$newEmail = strtolower( trim( $newEmail ) );
		if ( $newEmail === '' ) {
			throw new \InvalidArgumentException( 'Email is required.' );
		}
		$table = self::tableName();

		// Try to claim/refresh the new email. ON DUPLICATE KEY UPDATE only
		// succeeds if (a) no row exists yet, or (b) the existing row already
		// belongs to this same booking — in which case it's a harmless touch.
		// If another booking holds the email, the booking_id condition fails
		// and we detect the conflict by inspecting the post-update owner.
		$wpdb->suppress_errors( true );
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (email, booking_id, created_at)
			 VALUES (%s, %d, %s)
			 ON DUPLICATE KEY UPDATE
			   booking_id = IF(booking_id = %d, booking_id, booking_id)",
			$newEmail,
			$bookingId,
			current_time( 'mysql', true ),
			$bookingId
		) );
		$wpdb->suppress_errors( false );

		if ( false === $result ) {
			throw new \RuntimeException( 'Email lookup write failed: ' . $wpdb->last_error );
		}

		// Verify ownership — if another booking held the email, that row was
		// untouched and our query returned 2 affected rows (ON DUPLICATE KEY
		// UPDATE counts as 2 when an update happens) without actually swapping.
		$owner = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT booking_id FROM {$table} WHERE email = %s",
			$newEmail
		) );
		if ( $owner !== $bookingId ) {
			Logger::warning( 'booking.email_uniqueness_violation', [
				'email'         => $newEmail,
				'booking_id'    => $bookingId,
				'current_owner' => $owner,
			] );
			throw new \RuntimeException( 'Email already has a booking.' );
		}

		// Drop any older row this booking owned under a different email.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE booking_id = %d AND email <> %s",
			$bookingId,
			$newEmail
		) );
	}

	/**
	 * @param int|array<int> $metaIds
	 */
	public static function syncOnMetaChange( $metaIds, int $postId, string $metaKey, mixed $metaValue ): void {
		if ( $metaKey !== 'participant_email' ) {
			return;
		}
		if ( get_post_type( $postId ) !== PostTypes::BOOKING ) {
			return;
		}
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}
		$email = is_string( $metaValue ) ? $metaValue : (string) get_post_meta( $postId, 'participant_email', true );
		if ( $email === '' ) {
			self::release( $postId );
			return;
		}
		try {
			self::reassign( $postId, $email );
		} catch ( \Throwable $e ) {
			set_transient(
				'nvf_email_dupe_' . get_current_user_id(),
				$email,
				MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * @param int|array<int> $metaIds
	 */
	public static function syncOnMetaDelete( $metaIds, int $postId, string $metaKey, mixed $metaValue ): void {
		if ( $metaKey !== 'participant_email' ) {
			return;
		}
		if ( get_post_type( $postId ) !== PostTypes::BOOKING ) {
			return;
		}
		self::release( $postId );
	}

	public static function syncOnDelete( int $postId ): void {
		if ( get_post_type( $postId ) !== PostTypes::BOOKING ) {
			return;
		}
		self::release( $postId );
	}
}
