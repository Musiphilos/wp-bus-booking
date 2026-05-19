<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Admin;

use NVF\BusBooking\Booking\BookingReference;
use NVF\BusBooking\Booking\BookingService;
use NVF\BusBooking\Booking\SeatLedger;
use NVF\BusBooking\Domain\PostTypes;
use NVF\BusBooking\Integrations\GoogleSheetsWebhook;
use NVF\BusBooking\Mail\BookingContext;
use NVF\BusBooking\Mail\Mailer;
use NVF\BusBooking\Support\Logger;

/**
 * Per-trip printable manifest, CSV export, and waitlist-promote action.
 *
 *  - Display     : ?page=nvf-bus-booking-manifest&trip=<id>
 *  - CSV export  : admin-post.php?action=nvf_export_csv&trip=<id>
 *  - Promote     : admin-post.php?action=nvf_promote_waitlist&booking=<id>&trip=<id>&direction=<inbound|outbound>
 */
final class ManifestPage {

	public const SLUG = 'nvf-bus-booking-manifest';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 30 );
		add_action( 'admin_post_nvf_export_csv',         [ self::class, 'handleExportCsv' ] );
		add_action( 'admin_post_nvf_promote_waitlist',   [ self::class, 'handlePromoteWaitlist' ] );
		add_action( 'admin_post_nvf_promote_bulk',       [ self::class, 'handlePromoteBulk' ] );
	}

	public static function menu(): void {
		// Hidden from the menu but still registered so the page exists. Linked from the dashboard.
		add_submenu_page(
			null,
			__( 'Manifest', 'nvf-bus-booking' ),
			__( 'Manifest', 'nvf-bus-booking' ),
			AdminMenu::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function manifestUrl( int $tripId ): string {
		return add_query_arg( [ 'page' => self::SLUG, 'trip' => $tripId ], admin_url( 'admin.php' ) );
	}

	public static function csvUrl( int $tripId ): string {
		return wp_nonce_url(
			add_query_arg( [ 'action' => 'nvf_export_csv', 'trip' => $tripId ], admin_url( 'admin-post.php' ) ),
			'nvf_export_csv_' . $tripId
		);
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'nvf-bus-booking' ) );
		}

		$tripId = isset( $_GET['trip'] ) ? (int) $_GET['trip'] : 0;
		$trip   = $tripId > 0 ? get_post( $tripId ) : null;
		if ( ! $trip || $trip->post_type !== PostTypes::TRIP ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Manifest', 'nvf-bus-booking' ) . '</h1><p>' . esc_html__( 'Trip not found.', 'nvf-bus-booking' ) . '</p></div>';
			return;
		}

		$direction = (string) get_post_meta( $tripId, 'direction', true );
		$rows      = self::manifestRows( $tripId, $direction );
		$capacity  = (int) ( get_post_meta( $tripId, 'capacity', true ) ?: 0 );
		$departure = self::lisbonHuman( (string) get_post_meta( $tripId, 'departure_datetime', true ) );
		$confirmed = SeatLedger::countConfirmed( $tripId );
		$waitlist  = count( array_filter( $rows, static fn( $r ) => $r['status'] === 'waitlist' ) );

		$pickupBreakdown = self::pickupBreakdown( $rows );

		$tripCode    = (string) get_post_meta( $tripId, 'trip_code', true );
		$promoteDir  = str_starts_with( $direction, 'OPO-IN' ) ? 'inbound' : 'outbound';
		$bulkCount   = isset( $_GET['bulk_promoted'] ) ? (int) $_GET['bulk_promoted'] : -1;
		?>
		<div class="wrap nvf-admin nvf-manifest">
			<?php if ( $bulkCount >= 0 ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php
					echo esc_html( sprintf(
						_n( '%d passenger promoted from waitlist.', '%d passengers promoted from waitlist.', $bulkCount, 'nvf-bus-booking' ),
						$bulkCount
					) );
				?></p></div>
			<?php endif; ?>

			<div class="nvf-manifest__bar no-print">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG ) ); ?>">← <?php esc_html_e( 'Back to dashboard', 'nvf-bus-booking' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::csvUrl( $tripId ) ); ?>"><?php esc_html_e( 'Download CSV', 'nvf-bus-booking' ); ?></a>
				<button type="button" class="button button-primary" onclick="window.print()"><?php esc_html_e( 'Print', 'nvf-bus-booking' ); ?></button>
			</div>

			<h1 class="nvf-manifest__title"><?php echo esc_html( $tripCode ); ?></h1>

			<nav class="nvf-breadcrumb no-print" aria-label="<?php esc_attr_e( 'Breadcrumb', 'nvf-bus-booking' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG ) ); ?>"><?php esc_html_e( 'Bus Booking', 'nvf-bus-booking' ); ?></a>
				<span class="nvf-breadcrumb__sep" aria-hidden="true">›</span>
				<span><?php echo esc_html( $tripCode ); ?> — <?php esc_html_e( 'Manifest', 'nvf-bus-booking' ); ?></span>
			</nav>

			<p class="nvf-manifest__meta">
				<?php echo esc_html( $departure ); ?> ·
				<?php echo (int) $confirmed; ?> / <?php echo (int) $capacity; ?> <?php esc_html_e( 'confirmed', 'nvf-bus-booking' ); ?> ·
				<?php echo (int) $waitlist; ?> <?php esc_html_e( 'on waitlist', 'nvf-bus-booking' ); ?>
				<?php if ( $pickupBreakdown ) : ?>
					· <?php echo esc_html( $pickupBreakdown ); ?>
				<?php endif; ?>
			</p>

			<div class="nvf-manifest-search no-print">
				<input
					type="search"
					id="nvf-manifest-search"
					class="nvf-manifest-search__input"
					placeholder="<?php esc_attr_e( 'Filter passengers…', 'nvf-bus-booking' ); ?>"
					aria-label="<?php esc_attr_e( 'Filter passengers', 'nvf-bus-booking' ); ?>"
				>
				<span class="nvf-manifest-search__count" id="nvf-manifest-count" aria-live="polite"></span>
			</div>

			<table class="widefat striped nvf-manifest__table nvf-manifest-table">
				<thead>
					<tr>
						<th class="nvf-cb-col no-print"><input type="checkbox" id="nvf-select-all-waitlist" title="<?php esc_attr_e( 'Select all waitlist passengers', 'nvf-bus-booking' ); ?>" /></th>
						<th class="nvf-tick">✓</th>
						<th><?php esc_html_e( 'Name', 'nvf-bus-booking' ); ?></th>
						<th><?php esc_html_e( 'Email', 'nvf-bus-booking' ); ?></th>
						<th><?php esc_html_e( 'Phone', 'nvf-bus-booking' ); ?></th>
						<?php if ( $direction === 'OPO-IN' ) : ?>
							<th><?php esc_html_e( 'Pickup', 'nvf-bus-booking' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Status', 'nvf-bus-booking' ); ?></th>
						<th><?php esc_html_e( 'Ref', 'nvf-bus-booking' ); ?></th>
						<th class="nvf-actions"><?php esc_html_e( 'Actions', 'nvf-bus-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="<?php echo $direction === 'OPO-IN' ? 9 : 8; ?>"><em><?php esc_html_e( 'No bookings on this trip yet.', 'nvf-bus-booking' ); ?></em></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$searchKey = strtolower( ( $r['name'] ?? '' ) . ' ' . ( $r['email'] ?? '' ) . ' ' . self::pickupLabel( $r['pickup'] ?? '' ) );
						?>
						<tr class="nvf-status-<?php echo esc_attr( $r['status'] ); ?> nvf-manifest-row" data-search="<?php echo esc_attr( $searchKey ); ?>">
							<td class="nvf-cb-col no-print">
								<?php if ( $r['status'] === 'waitlist' ) : ?>
									<input type="checkbox" class="nvf-waitlist-cb" name="promote_ids[]" value="<?php echo (int) $r['booking_id']; ?>" form="nvf-bulk-promote-form" />
								<?php endif; ?>
							</td>
							<td class="nvf-tick"><input type="checkbox" /></td>
							<td><?php echo esc_html( $r['name'] ?: '—' ); ?></td>
							<td><a href="mailto:<?php echo esc_attr( $r['email'] ); ?>"><?php echo esc_html( $r['email'] ); ?></a></td>
							<td><?php echo esc_html( $r['phone'] ?: '—' ); ?></td>
							<?php if ( $direction === 'OPO-IN' ) : ?>
								<td><?php echo esc_html( self::pickupLabel( $r['pickup'] ) ); ?></td>
							<?php endif; ?>
							<td><span class="nvf-pill nvf-pill--<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( ucfirst( $r['status'] ) ); ?></span></td>
							<td><code><?php echo esc_html( $r['booking_ref'] ); ?></code></td>
							<td class="nvf-actions no-print">
								<?php if ( $r['status'] === 'waitlist' ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
										<?php wp_nonce_field( 'nvf_promote_' . $r['booking_id'] ); ?>
										<input type="hidden" name="action" value="nvf_promote_waitlist" />
										<input type="hidden" name="booking" value="<?php echo (int) $r['booking_id']; ?>" />
										<input type="hidden" name="trip" value="<?php echo (int) $tripId; ?>" />
										<input type="hidden" name="direction" value="<?php echo esc_attr( $promoteDir ); ?>" />
										<button type="submit" class="button button-small"><?php esc_html_e( 'Promote', 'nvf-bus-booking' ); ?></button>
									</form>
								<?php endif; ?>
								<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $r['booking_id'] ) ); ?>"><?php esc_html_e( 'Open', 'nvf-bus-booking' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			// Print-only boarding sheet: dense two-column grid with just what
			// the boarding crew needs to tick passengers off. Only confirmed
			// passengers are included; waitlist + cancelled are excluded.
			// Sorted by pickup (AIR before C.Mus) then by first name so the
			// crew at each stop can read straight down the list.
			$boarding = array_values( array_filter( $rows, static fn( $r ) => ( $r['status'] ?? '' ) === 'confirmed' ) );
			usort( $boarding, static function ( $a, $b ) {
				$pa = self::pickupShort( $a['pickup'] ?? '' );
				$pb = self::pickupShort( $b['pickup'] ?? '' );
				if ( $pa !== $pb ) {
					// Empty pickup (outbound) sinks to the end; otherwise alphabetical
					// (AIR before C.Mus, which is what the inbound crew expects).
					if ( $pa === '' ) return 1;
					if ( $pb === '' ) return -1;
					return strcmp( $pa, $pb );
				}
				$fa = strtolower( strtok( trim( (string) ( $a['name'] ?? '' ) ), ' ' ) ?: '' );
				$fb = strtolower( strtok( trim( (string) ( $b['name'] ?? '' ) ), ' ' ) ?: '' );
				return strcmp( $fa, $fb );
			} );
			?>
			<ol class="nvf-print-grid">
				<?php foreach ( $boarding as $r ) : ?>
					<?php $pickupShort = self::pickupShort( $r['pickup'] ?? '' ); ?>
					<li class="nvf-print-grid__item">
						<span class="nvf-print-grid__box" aria-hidden="true"></span>
						<span class="nvf-print-grid__name"><?php echo esc_html( $r['name'] ?: '—' ); ?></span>
						<span class="nvf-print-grid__phone"><?php echo esc_html( $r['phone'] ?: '—' ); ?></span>
						<?php if ( $pickupShort !== '' ) : ?>
							<span class="nvf-print-grid__pickup"><?php echo esc_html( $pickupShort ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>

			<form id="nvf-bulk-promote-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="no-print" style="margin-top:1rem;">
				<?php wp_nonce_field( 'nvf_promote_bulk_' . $tripId, 'nvf_bulk_nonce' ); ?>
				<input type="hidden" name="action"    value="nvf_promote_bulk" />
				<input type="hidden" name="trip"      value="<?php echo (int) $tripId; ?>" />
				<input type="hidden" name="direction" value="<?php echo esc_attr( $promoteDir ); ?>" />
				<button type="submit" id="nvf-bulk-promote-btn" class="button button-primary" disabled>
					<?php esc_html_e( 'Promote selected from waitlist', 'nvf-bus-booking' ); ?>
					(<span id="nvf-bulk-count">0</span>)
				</button>
			</form>
		</div>

		<style>
			.nvf-manifest__bar    { display: flex; gap: 8px; margin: 12px 0 16px; flex-wrap: wrap; }
			.nvf-manifest__title  { font-size: 28px; margin: 0; color: #036773; }
			.nvf-manifest__meta   { color: #50575e; margin: 4px 0 18px; }
			.nvf-manifest__table  { table-layout: auto; }
			.nvf-manifest__table th, .nvf-manifest__table td { vertical-align: middle; }
			.nvf-manifest__table .nvf-tick { width: 36px; text-align: center; }
			.nvf-manifest__table .nvf-actions { width: 220px; }
			.nvf-pill {
				display: inline-block; padding: 2px 8px; font-size: 11px; font-weight: 700;
				letter-spacing: 0.08em; text-transform: uppercase; border-radius: 2px;
				border: 1px solid currentColor;
			}
			.nvf-pill--confirmed { background: #ecfdf5; color: #166534; }
			.nvf-pill--waitlist  { background: #fef7e1; color: #92580a; }
			.nvf-pill--cancelled { background: #fdecec; color: #881812; }

			/* Print-only boarding grid is hidden on screen. */
			.nvf-print-grid { display: none; }

			@media print {
				@page { size: A4 portrait; margin: 10mm 12mm; }

				#adminmenumain, #wpadminbar, #wpfooter, #screen-meta, #screen-meta-links,
				.nvf-manifest__bar, .no-print, .notice, .update-nag { display: none !important; }
				#wpcontent { padding-left: 0 !important; margin-left: 0 !important; }
				html.wp-toolbar { padding-top: 0 !important; }
				body { background: #fff; }

				.nvf-manifest__title { font-size: 18px; margin: 0; }
				.nvf-manifest__meta  { font-size: 10px; margin: 2px 0 10px; }

				/* Hide the on-screen admin table; print uses the dedicated boarding grid below. */
				.nvf-manifest__table,
				.nvf-manifest-search { display: none !important; }

				/* Two-column boarding grid: each row is a single line — checkbox,
				 * bold name, muted phone, pickup tag — so ~30 rows fit per
				 * column (60 per A4 page). */
				.nvf-print-grid {
					display: block;
					column-count: 2;
					column-gap: 8mm;
					margin: 0;
					padding: 0;
					list-style: decimal-leading-zero inside;
					font-size: 10pt;
					line-height: 1.15;
					color: #000;
				}
				.nvf-print-grid__item {
					break-inside: avoid;
					page-break-inside: avoid;
					padding: 2px 0;
					border-bottom: 1px dashed #bbb;
					display: flex;
					align-items: center;
					gap: 6px;
				}
				.nvf-print-grid__item::marker { font-size: 7.5pt; color: #666; }
				.nvf-print-grid__box {
					flex: 0 0 auto;
					width: 10px;
					height: 10px;
					border: 1px solid #000;
				}
				.nvf-print-grid__name  { font-weight: 700; }
				.nvf-print-grid__phone {
					font-size: 9pt;
					color: #444;
					font-variant-numeric: tabular-nums;
					margin-left: auto;
				}
				.nvf-print-grid__pickup {
					flex: 0 0 auto;
					font-size: 7.5pt;
					font-weight: 700;
					letter-spacing: 0.05em;
					padding: 0 3px;
					border: 1px solid #000;
					border-radius: 2px;
				}

				a, a:visited { color: inherit; text-decoration: none; }
			}
		</style>
		<?php
	}

	public static function handleExportCsv(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'nvf-bus-booking' ) );
		}
		$tripId = isset( $_GET['trip'] ) ? (int) $_GET['trip'] : 0;
		check_admin_referer( 'nvf_export_csv_' . $tripId );

		if ( ! $tripId || get_post_type( $tripId ) !== PostTypes::TRIP ) {
			wp_die( esc_html__( 'Unknown trip.', 'nvf-bus-booking' ) );
		}

		$direction = (string) get_post_meta( $tripId, 'direction', true );
		$rows      = self::manifestRows( $tripId, $direction );
		$code      = (string) get_post_meta( $tripId, 'trip_code', true );

		// Drain any output a theme/plugin may have started — leftover whitespace
		// or a stray BOM would corrupt the CSV. Safer to be paranoid here than
		// to debug a malformed download later.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $code . '-manifest.csv' ) . '"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM for Excel.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, [ 'Name', 'Email', 'Phone', 'Direction', 'Pickup', 'Status', 'Source', 'Booking Ref' ] );
		foreach ( $rows as $r ) {
			fputcsv( $out, [
				$r['name'],
				$r['email'],
				$r['phone'],
				$direction,
				self::pickupLabel( $r['pickup'] ),
				$r['status'],
				$r['source'],
				$r['booking_ref'],
			] );
		}
		fclose( $out );
		Logger::info( 'admin.csv_exported', [ 'trip' => $tripId, 'rows' => count( $rows ) ] );
		exit;
	}

	public static function handlePromoteWaitlist(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'nvf-bus-booking' ) );
		}
		$bookingId = isset( $_POST['booking'] ) ? (int) $_POST['booking'] : 0;
		$tripId    = isset( $_POST['trip'] )    ? (int) $_POST['trip']    : 0;
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
		check_admin_referer( 'nvf_promote_' . $bookingId );

		if ( ! $bookingId || ! $tripId || ! in_array( $direction, [ 'inbound', 'outbound' ], true ) ) {
			wp_die( esc_html__( 'Bad request.', 'nvf-bus-booking' ) );
		}

		$statusKey = $direction === 'inbound' ? 'inbound_status' : 'outbound_status';
		$current   = (string) get_post_meta( $bookingId, $statusKey, true );
		if ( $current !== 'waitlist' ) {
			wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'trip' => $tripId, 'promoted' => 'not_waitlist' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		$claim = SeatLedger::claim( $tripId, $bookingId, $direction );
		if ( $claim !== 'confirmed' ) {
			wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'trip' => $tripId, 'promoted' => 'no_capacity' ], admin_url( 'admin.php' ) ) );
			exit;
		}
		update_post_meta( $bookingId, $statusKey, 'confirmed' );

		// Email confirmation to the participant.
		try {
			$email = (string) get_post_meta( $bookingId, 'participant_email', true );
			$ctx   = BookingContext::build( $bookingId );
			Mailer::sendConfirmation( $email, $ctx );
			Mailer::sendAdminNotification( 'booking.promoted', $ctx );
		} catch ( \Throwable $e ) {
			Logger::error( 'admin.promote_mail_failed', [ 'booking' => $bookingId, 'reason' => $e->getMessage() ] );
		}

		try {
			GoogleSheetsWebhook::dispatch( 'booking.promoted', $bookingId );
		} catch ( \Throwable $e ) {
			Logger::error( 'admin.promote_sheets_failed', [ 'booking' => $bookingId, 'reason' => $e->getMessage() ] );
		}

		Logger::info( 'admin.promoted_waitlist', [ 'booking' => $bookingId, 'trip' => $tripId, 'direction' => $direction ] );
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'trip' => $tripId, 'promoted' => 'ok' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handlePromoteBulk(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'nvf-bus-booking' ) );
		}
		$tripId    = isset( $_POST['trip'] ) ? (int) $_POST['trip'] : 0;
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';
		check_admin_referer( 'nvf_promote_bulk_' . $tripId, 'nvf_bulk_nonce' );

		if ( ! $tripId || ! in_array( $direction, [ 'inbound', 'outbound' ], true ) ) {
			wp_die( esc_html__( 'Bad request.', 'nvf-bus-booking' ) );
		}

		$ids       = isset( $_POST['promote_ids'] ) ? array_map( 'intval', (array) $_POST['promote_ids'] ) : [];
		$statusKey = $direction === 'inbound' ? 'inbound_status' : 'outbound_status';
		$promoted  = 0;

		foreach ( $ids as $bookingId ) {
			if ( $bookingId <= 0 || get_post_type( $bookingId ) !== PostTypes::BOOKING ) {
				continue;
			}
			if ( (string) get_post_meta( $bookingId, $statusKey, true ) !== 'waitlist' ) {
				continue;
			}

			$claim = SeatLedger::claim( $tripId, $bookingId, $direction );
			if ( $claim !== 'confirmed' ) {
				// Trip is full — stop processing; any remaining picks would also fail.
				break;
			}
			update_post_meta( $bookingId, $statusKey, 'confirmed' );
			$promoted++;

			try {
				$email = (string) get_post_meta( $bookingId, 'participant_email', true );
				$ctx   = BookingContext::build( $bookingId );
				Mailer::sendConfirmation( $email, $ctx );
				Mailer::sendAdminNotification( 'booking.promoted', $ctx );
			} catch ( \Throwable $e ) {
				Logger::error( 'admin.promote_bulk_mail_failed', [ 'booking' => $bookingId, 'reason' => $e->getMessage() ] );
			}

			try {
				GoogleSheetsWebhook::dispatch( 'booking.promoted', $bookingId );
			} catch ( \Throwable $e ) {
				Logger::error( 'admin.promote_bulk_sheets_failed', [ 'booking' => $bookingId, 'reason' => $e->getMessage() ] );
			}

			Logger::info( 'admin.promoted_waitlist_bulk', [ 'booking' => $bookingId, 'trip' => $tripId, 'direction' => $direction ] );
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::SLUG, 'trip' => $tripId, 'bulk_promoted' => $promoted ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// --------------------------------------------------------------------

	/** @return array<int,array> */
	private static function manifestRows( int $tripId, string $direction ): array {
		$isInbound = str_starts_with( $direction, 'OPO-IN' );
		$tripKey   = $isInbound ? 'inbound_trip_id' : 'outbound_trip_id';
		$statusKey = $isInbound ? 'inbound_status'  : 'outbound_status';

		$q = new \WP_Query( [
			'post_type'      => PostTypes::BOOKING,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => $tripKey,   'value' => $tripId,   'compare' => '=' ],
				[ 'key' => $statusKey, 'value' => 'none',     'compare' => '!=' ],
				[ 'key' => $statusKey, 'value' => 'cancelled', 'compare' => '!=' ],
			],
			// Confirmed first, then waitlist; within each, oldest first.
			'orderby' => 'date',
			'order'   => 'ASC',
		] );

		$out = [];
		foreach ( $q->posts as $post ) {
			$status = (string) get_post_meta( $post->ID, $statusKey, true );
			$out[]  = [
				'booking_id'  => $post->ID,
				'name'        => (string) get_post_meta( $post->ID, 'participant_name',  true ),
				'email'       => (string) get_post_meta( $post->ID, 'participant_email', true ),
				'phone'       => (string) get_post_meta( $post->ID, 'participant_phone', true ),
				'pickup'      => $isInbound ? (string) get_post_meta( $post->ID, 'inbound_pickup_location', true ) : '',
				'status'      => $status,
				'source'      => (string) get_post_meta( $post->ID, 'source', true ) ?: 'public',
				'booking_ref' => BookingReference::for( $post->ID ),
			];
		}
		wp_reset_postdata();

		usort( $out, static function ( $a, $b ) {
			$rank = [ 'confirmed' => 0, 'waitlist' => 1, 'cancelled' => 2 ];
			return ( $rank[ $a['status'] ] ?? 9 ) <=> ( $rank[ $b['status'] ] ?? 9 );
		} );
		return $out;
	}

	private static function pickupBreakdown( array $rows ): string {
		$counts = [ 'airport' => 0, 'casa_da_musica' => 0 ];
		foreach ( $rows as $r ) {
			if ( $r['status'] !== 'confirmed' ) {
				continue;
			}
			if ( isset( $counts[ $r['pickup'] ] ) ) {
				$counts[ $r['pickup'] ]++;
			}
		}
		if ( $counts['airport'] === 0 && $counts['casa_da_musica'] === 0 ) {
			return '';
		}
		return sprintf( 'Airport %d · Casa da Música %d', $counts['airport'], $counts['casa_da_musica'] );
	}

	private static function pickupLabel( string $code ): string {
		return [
			'airport'        => 'Airport',
			'casa_da_musica' => 'Casa da Música',
		][ $code ] ?? ( $code ?: '—' );
	}

	/** Boarding-sheet abbreviation. Empty for unset/outbound. */
	private static function pickupShort( string $code ): string {
		return [
			'airport'        => 'AIR',
			'casa_da_musica' => 'C.Mus',
		][ $code ] ?? '';
	}

	private static function lisbonHuman( string $dt ): string {
		if ( $dt === '' ) {
			return '—';
		}
		$formatted = \NVF\BusBooking\Support\Time::formatHuman( $dt );
		return $formatted !== '' ? $formatted : $dt;
	}
}
