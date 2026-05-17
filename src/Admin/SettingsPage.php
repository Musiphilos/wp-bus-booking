<?php

declare( strict_types=1 );

namespace NVF\BusBooking\Admin;

use NVF\BusBooking\Auth\Mailer;
use NVF\BusBooking\Domain\StringsRegistry;
use NVF\BusBooking\Support\Logger;

/**
 * Unified Settings page with tabs.
 *
 *   General           — operational settings (event dates, sender, etc.)
 *   Booking page      — editorial copy for the public-facing form
 *   Email · …         — one tab per template
 *   PDF ticket        — printable ticket copy
 *
 * Storage layout is unchanged from the previous split:
 *   - General fields live in the serialized `nvf_settings` option.
 *   - Editorial strings live in the serialized `nvf_strings` option,
 *     keyed by `StringsRegistry` ids.
 *
 * The page is a custom render (no Meta Box) so we control the tab nav, the
 * field layout, and the token-chip UX in one place.
 */
final class SettingsPage {

	public const SLUG        = 'nvf-bus-booking-settings';
	public const SAVE_ACTION = 'nvf_save_settings';
	public const OPTION_KEY  = 'nvf_settings';
	public const STRINGS_KEY = 'nvf_strings';

	/**
	 * Tab key → [ label, dashicon ]. Order here drives the nav strip.
	 * Icons come from core WordPress Dashicons (already loaded in wp-admin),
	 * so there is nothing to enqueue.
	 */
	private const TABS = [
		'general'            => [ 'label' => 'General',                          'icon' => 'admin-settings' ],
		'booking_page'       => [ 'label' => 'Booking page',                     'icon' => 'tickets-alt' ],
		'email_magic_link'   => [ 'label' => 'Email · Magic link',               'icon' => 'admin-network' ],
		'email_confirmation' => [ 'label' => 'Email · Confirmation',             'icon' => 'yes-alt' ],
		'email_cancellation' => [ 'label' => 'Email · Cancellation',             'icon' => 'dismiss' ],
		'email_admin'        => [ 'label' => 'Email · Admin notification',       'icon' => 'businessperson' ],
		'email_spot_opened'  => [ 'label' => 'Email · Waitlist · Spot opened',   'icon' => 'bell' ],
		'email_spot_taken'   => [ 'label' => 'Email · Waitlist · Spot taken',    'icon' => 'warning' ],
		'pdf'                => [ 'label' => 'PDF ticket',                       'icon' => 'media-document' ],
	];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 22 );
		add_action( 'admin_post_' . self::SAVE_ACTION, [ self::class, 'handleSave' ] );
		add_action( 'admin_post_nvf_send_test_email',  [ self::class, 'handleSendTest' ] );
		add_action( 'admin_post_nvf_rotate_secret',    [ self::class, 'handleRotateSecret' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			AdminMenu::SLUG,
			__( 'Settings', 'nvf-bus-booking' ),
			__( 'Settings', 'nvf-bus-booking' ),
			AdminMenu::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'nvf-bus-booking' ) );
		}

		$tab = self::activeTab();
		$saved = isset( $_GET['saved'] ) ? (int) $_GET['saved'] : 0;
		?>
		<?php $catalog = self::stringsCatalog(); ?>
		<div class="wrap nvf-admin nvf-settings">
			<h1><?php esc_html_e( 'Bus Booking · Settings', 'nvf-bus-booking' ); ?></h1>

			<div class="nvf-settings__search">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<input type="search" id="nvf-settings-search"
				       placeholder="<?php esc_attr_e( 'Find a string — by label, id, default text, or current value…', 'nvf-bus-booking' ); ?>"
				       aria-label="<?php esc_attr_e( 'Search editable strings', 'nvf-bus-booking' ); ?>" />
				<button type="button" class="nvf-settings__search-clear" aria-label="<?php esc_attr_e( 'Clear search', 'nvf-bus-booking' ); ?>" hidden>×</button>
			</div>

			<div class="nvf-settings__search-results" id="nvf-settings-search-results" hidden>
				<p class="nvf-settings__search-results-summary" id="nvf-settings-search-summary"></p>
				<div class="nvf-settings__search-body" id="nvf-settings-search-body"></div>
				<p class="nvf-settings__search-empty" id="nvf-settings-search-empty" hidden></p>
			</div>

			<nav class="nav-tab-wrapper nvf-settings__tabs" style="margin-bottom: 1rem;">
				<?php foreach ( self::TABS as $key => $meta ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"
					   href="<?php echo esc_url( self::tabUrl( $key ) ); ?>">
						<span class="dashicons dashicons-<?php echo esc_attr( $meta['icon'] ); ?>" aria-hidden="true"></span>
						<span class="nvf-settings__tab-label"><?php echo esc_html( $meta['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Saved.', 'nvf-bus-booking' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['test'] ) ) : ?>
				<div class="notice notice-<?php echo $_GET['test'] === 'ok' ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo $_GET['test'] === 'ok'
						? esc_html__( 'Test email accepted by the mail stack. Check inbox + spam.', 'nvf-bus-booking' )
						: esc_html__( 'Test email FAILED. Check the mail stack and try again.', 'nvf-bus-booking' );
					?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['rotated'] ) ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php esc_html_e( 'Signing secret rotated. All magic links and sessions issued before now are invalid.', 'nvf-bus-booking' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvf-settings__form">
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />

				<div class="nvf-unsaved-banner" id="nvf-unsaved-banner" role="status">
					<?php esc_html_e( 'You have unsaved changes — remember to save before leaving.', 'nvf-bus-booking' ); ?>
				</div>

				<div class="nvf-settings__tab-content">
					<?php
					if ( $tab === 'general' ) {
						self::renderGeneralTab();
					} else {
						self::renderStringsTab( $tab );
					}
					?>
				</div>

				<?php submit_button( __( 'Save changes', 'nvf-bus-booking' ) ); ?>

				<?php self::renderRowTemplates(); ?>
			</form>

			<?php
			// Operations + Danger Zone host their own nested <form> elements
			// (Send test email, Rotate signing secret). HTML forbids nested
			// forms — keep these outside the main settings form so they don't
			// detach the Save button from it. Wrapped in a container so the
			// settings search panel can hide them while a query is active.
			if ( $tab === 'general' ) {
				echo '<div class="nvf-settings__ops">';
				self::renderOperationsSection();
				echo '</div>';
			}
			?>
		</div>

		<?php self::renderStyles(); ?>
		<?php
		// Token chip + reset behaviours must work everywhere — cloned rows in
		// the search panel need them too, even when the active tab is General.
		self::renderTokenScript();
		self::renderSearchScript( $catalog );
		?>
		<?php
	}

	/**
	 * Build a JSON-friendly catalog of every editable string for the live
	 * search. Done server-side so we have access to the canonical default,
	 * the current override, AND the tab metadata in one pass.
	 *
	 * @return array<int,array{id:string,label:string,description:string,default:string,current:string,overridden:bool,tab:string,tab_label:string}>
	 */
	private static function stringsCatalog(): array {
		$bag = get_option( self::STRINGS_KEY, [] );
		$bag = is_array( $bag ) ? $bag : [];
		$out = [];
		foreach ( StringsRegistry::declarations() as $entry ) {
			$id   = $entry['id'];
			$tab  = $entry['group'];
			$cur  = $bag[ $id ] ?? '';
			$over = isset( $bag[ $id ] ) && ( $cur !== '' || ! empty( $entry['blank_hides'] ) );
			$out[] = [
				'id'          => $id,
				'label'       => $entry['label'],
				'description' => $entry['description'],
				'default'     => (string) $entry['default'],
				'current'     => (string) $cur,
				'overridden'  => $over,
				'tab'         => $tab,
				'tab_label'   => self::TABS[ $tab ]['label'] ?? $tab,
			];
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// General tab
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Field schema for the General tab. One section per logical group.
	 *
	 * Each field declares:
	 *   id, type, label, desc?, std?, min?, max?, step?, placeholder?
	 * Where type ∈ {text, email, url, date, number, switch, email_list}.
	 */
	private static function generalFields(): array {
		return [
			'Event' => [
				[ 'id' => 'nvf_event_start_date',         'type' => 'date',   'label' => __( 'Event start date', 'nvf-bus-booking' ),
				  'desc' => __( 'Used to compute cancellation deadlines.', 'nvf-bus-booking' ) ],
				[ 'id' => 'nvf_event_end_date',           'type' => 'date',   'label' => __( 'Event end date', 'nvf-bus-booking' ),
				  'desc' => __( 'Used as the origin for booking retention. Defaults to start + 4 days.', 'nvf-bus-booking' ) ],
				[ 'id' => 'nvf_cancellation_days_before', 'type' => 'number', 'label' => __( 'Cancellation buffer (days)', 'nvf-bus-booking' ),
				  'std' => 1, 'min' => 0, 'max' => 30 ],
				[ 'id' => 'nvf_ticket_price_single',      'type' => 'number', 'label' => __( 'Ticket price — Single (€)', 'nvf-bus-booking' ),
				  'step' => '0.01', 'desc' => __( 'Charged when the participant books one direction only.', 'nvf-bus-booking' ) ],
				[ 'id' => 'nvf_ticket_price_double',      'type' => 'number', 'label' => __( 'Ticket price — Round-trip (€)', 'nvf-bus-booking' ),
				  'step' => '0.01', 'desc' => __( 'Charged when the participant books both inbound and outbound.', 'nvf-bus-booking' ) ],
			],
			'Email transport' => [
				[ 'id' => 'nvf_email_sender_name',        'type' => 'text',   'label' => __( 'Sender name', 'nvf-bus-booking' ),
				  'desc' => __( 'Display name shown on outgoing emails (From header).', 'nvf-bus-booking' ) ],
				[ 'id' => 'nvf_email_sender_address',     'type' => 'email',  'label' => __( 'Sender address', 'nvf-bus-booking' ),
				  'desc' => __( 'Must be a Brevo-verified address; used only as the From address.', 'nvf-bus-booking' ) ],
				[ 'id' => 'nvf_public_contact_email',     'type' => 'email',  'label' => __( 'Public contact email', 'nvf-bus-booking' ),
				  'desc' => __( 'Address shown to participants in transactional emails and on the booking page. Defaults to the sender address.', 'nvf-bus-booking' ) ],
				[ 'id' => 'nvf_admin_notification_recipients', 'type' => 'email_list', 'label' => __( 'Admin notification recipients', 'nvf-bus-booking' ),
				  'desc' => __( 'One or more addresses that receive an internal email on every booking / cancellation.', 'nvf-bus-booking' ) ],
			],
			'Integrations & policy' => [
				[ 'id' => 'nvf_elementor_form_id',        'type' => 'text',   'label' => __( 'Elementor registration form', 'nvf-bus-booking' ),
				  'std' => '997de44',
				  'desc' => __( 'Either the Elementor element_id (e.g. 997de44) or the form name (e.g. "Registrations 2026"). Whitespace is ignored.', 'nvf-bus-booking' ) ],
				[ 'id' => 'nvf_magic_link_expiry_hours',  'type' => 'number', 'label' => __( 'Magic-link expiry (hours)', 'nvf-bus-booking' ),
				  'std' => 24, 'min' => 1 ],
				[ 'id' => 'nvf_booking_retention_days',   'type' => 'number', 'label' => __( 'Booking retention (days after event)', 'nvf-bus-booking' ),
				  'std' => 90, 'min' => 0,
				  'desc' => __( 'After the event end date + this many days, public bookings are auto-purged.', 'nvf-bus-booking' ) ],
				[ 'id' => 'nvf_retention_purge_admin',    'type' => 'switch', 'label' => __( 'Auto-purge admin-added bookings too', 'nvf-bus-booking' ),
				  'desc' => __( 'Off (default): the retention sweep skips bookings with source = admin.', 'nvf-bus-booking' ) ],
				[ 'id' => 'nvf_privacy_policy_url',       'type' => 'url',    'label' => __( 'Privacy policy URL', 'nvf-bus-booking' ),
				  'desc' => __( 'Shown in the booking page footer. Must list the data this plugin collects.', 'nvf-bus-booking' ),
				  'placeholder' => 'https://lbswing.com/privacy/' ],
				[ 'id' => 'nvf_google_sheets_webhook_url', 'type' => 'url',   'label' => __( 'Google Sheets webhook URL', 'nvf-bus-booking' ),
				  'desc' => __( 'Apps Script web-app /exec URL. Leave blank to disable.', 'nvf-bus-booking' ),
				  'placeholder' => 'https://script.google.com/macros/s/AKfycb…/exec' ],
			],
		];
	}

	private static function renderGeneralTab(): void {
		$bag = get_option( self::OPTION_KEY, [] );
		$bag = is_array( $bag ) ? $bag : [];

		foreach ( self::generalFields() as $section => $fields ) {
			echo '<h2 class="nvf-settings__section">' . esc_html( $section ) . '</h2>';
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $fields as $field ) {
				self::renderGeneralRow( $field, $bag );
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Operational utilities rendered at the bottom of the General tab. These
	 * forms post to admin-post.php directly (outside the main settings form)
	 * so each has its own nonce and dedicated handler.
	 */
	private static function renderOperationsSection(): void {
		$currentEmail = wp_get_current_user()->user_email ?? '';
		?>
		<h2 class="nvf-settings__section"><?php esc_html_e( 'Operations', 'nvf-bus-booking' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="nvf-test-email-to"><?php esc_html_e( 'Send test email', 'nvf-bus-booking' ); ?></label></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;align-items:center;margin:0;flex-wrap:wrap;">
							<?php wp_nonce_field( 'nvf_send_test_email' ); ?>
							<input type="hidden" name="action" value="nvf_send_test_email" />
							<input type="email" id="nvf-test-email-to" name="to" required
							       class="regular-text"
							       placeholder="<?php echo esc_attr__( 'recipient@example.com', 'nvf-bus-booking' ); ?>"
							       value="<?php echo esc_attr( $currentEmail ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Send', 'nvf-bus-booking' ); ?></button>
						</form>
						<p class="description"><?php esc_html_e( 'Verifies the mail stack end-to-end. The recipient must be a real inbox you can check.', 'nvf-bus-booking' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<div class="nvf-danger-zone">
			<h3 class="nvf-danger-zone__title">⚠ <?php esc_html_e( 'Danger Zone', 'nvf-bus-booking' ); ?></h3>
			<p class="nvf-danger-zone__desc">
				<?php esc_html_e( 'Rotating the signing secret immediately invalidates every outstanding magic link and every active session cookie. All signed-in users will need to re-authenticate. Use this only after a suspected leak.', 'nvf-bus-booking' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;"
			      onsubmit="return confirm('<?php echo esc_js( __( 'Rotate the signing secret? Every outstanding magic link AND every active session cookie will be invalidated immediately. This cannot be undone.', 'nvf-bus-booking' ) ); ?>');">
				<?php wp_nonce_field( 'nvf_rotate_secret' ); ?>
				<input type="hidden" name="action" value="nvf_rotate_secret" />
				<button type="submit" class="button nvf-btn-danger"><?php esc_html_e( 'Rotate signing secret', 'nvf-bus-booking' ); ?></button>
			</form>
		</div>
		<?php
	}

	public static function handleSendTest(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to send test emails.', 'nvf-bus-booking' ) );
		}
		check_admin_referer( 'nvf_send_test_email' );
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( $to === '' ) {
			wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tab' => 'general', 'test' => 'fail' ], admin_url( 'admin.php' ) ) );
			exit;
		}
		$result = Mailer::sendTest( $to );
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tab' => 'general', 'test' => $result['ok'] ? 'ok' : 'fail' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handleRotateSecret(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to rotate the signing secret.', 'nvf-bus-booking' ) );
		}
		check_admin_referer( 'nvf_rotate_secret' );

		try {
			$new = base64_encode( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			$new = wp_generate_password( 64, true, true );
		}
		update_option( 'nvf_plugin_secret', $new, false );
		Logger::warning( 'admin.secret_rotated', [ 'by' => get_current_user_id() ] );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tab' => 'general', 'rotated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function renderGeneralRow( array $field, array $bag ): void {
		$id    = $field['id'];
		$value = $bag[ $id ] ?? ( $field['std'] ?? '' );
		$desc  = $field['desc'] ?? '';
		$name  = sprintf( 'nvf_general[%s]', $id );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
			<td>
				<?php self::renderGeneralInput( $field, $name, $value ); ?>
				<?php if ( $desc !== '' ) : ?>
					<p class="description"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function renderGeneralInput( array $field, string $name, $value ): void {
		$id = $field['id'];
		switch ( $field['type'] ) {
			case 'date':
				printf(
					'<input type="date" id="%s" name="%s" value="%s" />',
					esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%s" %s %s %s class="small-text" />',
					esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ),
					isset( $field['min'] )  ? 'min="'  . esc_attr( (string) $field['min'] )  . '"' : '',
					isset( $field['max'] )  ? 'max="'  . esc_attr( (string) $field['max'] )  . '"' : '',
					isset( $field['step'] ) ? 'step="' . esc_attr( (string) $field['step'] ) . '"' : ''
				);
				break;

			case 'switch':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s /> %s</label>',
					esc_attr( $id ), esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html__( 'Enabled', 'nvf-bus-booking' )
				);
				break;

			case 'url':
			case 'email':
			case 'text':
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text" />',
					esc_attr( $field['type'] ),
					esc_attr( $id ), esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $field['placeholder'] ?? '' )
				);
				break;

			case 'email_list':
				$list = is_array( $value ) ? $value : [];
				if ( empty( $list ) ) {
					$list = [ '' ];
				}
				echo '<div class="nvf-settings__list">';
				foreach ( $list as $i => $entry ) {
					printf(
						'<input type="email" name="%s[]" value="%s" class="regular-text" placeholder="%s" />',
						esc_attr( $name ), esc_attr( (string) $entry ),
						esc_attr__( 'admin@example.com', 'nvf-bus-booking' )
					);
				}
				echo '<button type="button" class="button button-small nvf-settings__list-add">+ ' . esc_html__( 'Add address', 'nvf-bus-booking' ) . '</button>';
				echo '</div>';
				break;
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Strings tabs
	// ─────────────────────────────────────────────────────────────────────────

	private static function renderStringsTab( string $group ): void {
		$entries = StringsRegistry::byGroup()[ $group ] ?? [];
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No fields registered for this tab.', 'nvf-bus-booking' ) . '</p>';
			return;
		}

		$bag = get_option( self::STRINGS_KEY, [] );
		$bag = is_array( $bag ) ? $bag : [];

		echo '<p class="description" style="margin: 1em 0;">';
		esc_html_e( 'Edit the editorial copy used on this surface. Leave a field blank to use the canonical default. Click a token chip to insert it at the cursor.', 'nvf-bus-booking' );
		echo '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $entries as $entry ) {
			self::renderStringRow( $entry, $bag );
		}
		echo '</tbody></table>';
	}

	private static function renderStringRow( array $entry, array $bag ): void {
		$id       = $entry['id'];
		$stored   = $bag[ $id ] ?? '';
		$override = ( $stored !== '' && $stored !== null ) || ( isset( $bag[ $id ] ) && ! empty( $entry['blank_hides'] ) );
		$isMulti  = in_array( $entry['type'], [ 'textarea', 'html' ], true );
		$placeholder = (string) $entry['default'];
		$inputName   = sprintf( 'nvf_strings[%s]', $id );
		$blankHides  = ! empty( $entry['blank_hides'] );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( 'nvf-' . $id ); ?>"><?php echo esc_html( $entry['label'] ); ?></label>
				<?php
				// Server-rendered initial state. JS reactively updates these as
				// the admin types so the hint always matches what would be saved.
				// Empty-equals-empty is NOT treated as "= default" — only
				// non-empty values that match the default trigger the hint, so
				// fields where both the default and the stored value are blank
				// stay visually neutral.
				$initialMatches = ( $stored !== '' && $stored === (string) $placeholder );
				$initialHidden  = $blankHides && isset( $bag[ $id ] ) && (string) $stored === '';
				$initialOver    = $override && ! $initialHidden && ! $initialMatches;
				?>
				<span class="nvf-settings__badge nvf-settings__badge--override"
				      <?php echo $initialOver ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Overridden', 'nvf-bus-booking' ); ?>
				</span>
				<span class="nvf-settings__badge nvf-settings__badge--default"
				      <?php echo $initialMatches ? '' : 'hidden'; ?>>
					<?php esc_html_e( '= default', 'nvf-bus-booking' ); ?>
				</span>
				<?php if ( $blankHides ) : ?>
					<span class="nvf-settings__badge nvf-settings__badge--hidden"
					      <?php echo $initialHidden ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Hidden', 'nvf-bus-booking' ); ?>
					</span>
				<?php endif; ?>
				<p class="description" style="font-weight:400;"><?php echo esc_html( $entry['description'] ); ?></p>
				<p class="description" style="color:#9ca3af;font-size:11px;"><code style="background:transparent;padding:0;font-size:11px;"><?php echo esc_html( $id ); ?></code></p>
			</th>
			<td>
				<?php
				$commonAttrs = sprintf(
					'data-default="%s" %s',
					esc_attr( $placeholder ),
					$blankHides ? 'data-blank-hides="1"' : ''
				);
				?>
				<?php if ( $isMulti ) : ?>
					<textarea
						id="<?php echo esc_attr( 'nvf-' . $id ); ?>"
						name="<?php echo esc_attr( $inputName ); ?>"
						class="nvf-settings__field nvf-settings__field--ta <?php echo $override ? 'is-overridden' : ''; ?>"
						rows="3"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						<?php echo $commonAttrs; ?>
					><?php echo esc_textarea( (string) $stored ); ?></textarea>
				<?php else : ?>
					<input
						type="text"
						id="<?php echo esc_attr( 'nvf-' . $id ); ?>"
						name="<?php echo esc_attr( $inputName ); ?>"
						class="nvf-settings__field <?php echo $override ? 'is-overridden' : ''; ?>"
						value="<?php echo esc_attr( (string) $stored ); ?>"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						<?php echo $commonAttrs; ?>
					/>
				<?php endif; ?>

				<div class="nvf-settings__help">
					<?php if ( ! empty( $entry['tokens'] ) ) : ?>
						<span><?php esc_html_e( 'Tokens:', 'nvf-bus-booking' ); ?></span>
						<?php foreach ( $entry['tokens'] as $token ) : ?>
							<button type="button" class="nvf-settings__chip"
							        data-token="<?php echo esc_attr( '{{' . $token . '}}' ); ?>"
							        aria-label="<?php echo esc_attr( sprintf( __( 'Insert %s at cursor', 'nvf-bus-booking' ), '{{' . $token . '}}' ) ); ?>">{{<?php echo esc_html( $token ); ?>}}</button>
						<?php endforeach; ?>
					<?php endif; ?>
					<button type="button" class="nvf-settings__reset"><?php esc_html_e( 'Reset to default', 'nvf-bus-booking' ); ?></button>
				</div>
			</td>
		</tr>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Save dispatch
	// ─────────────────────────────────────────────────────────────────────────

	public static function handleSave(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'nvf-bus-booking' ) );
		}
		check_admin_referer( self::SAVE_ACTION );

		$tab = self::processSave(
			isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general',
			wp_unslash( $_POST )
		);

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::SLUG, 'tab' => $tab, 'saved' => 1 ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Pure save: no auth, no redirect. Returns the tab the caller should land on.
	 * Usable from tests, bulk-import scripts, and the admin-post handler above.
	 *
	 * @param array<string,mixed> $post
	 */
	public static function processSave( string $tab, array $post ): string {
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'general';
		}

		// Both savers run on every submit so the search panel can edit strings
		// while the active tab is "General", and vice-versa. Each saver is a
		// no-op if its payload key is absent.
		if ( isset( $post['nvf_general'] ) && is_array( $post['nvf_general'] ) ) {
			self::saveGeneral( $post );
		}
		if ( isset( $post['nvf_strings'] ) && is_array( $post['nvf_strings'] ) ) {
			self::saveStrings( $tab, $post );
		}

		return $tab;
	}

	private static function saveGeneral( array $post ): void {
		$incoming = isset( $post['nvf_general'] ) && is_array( $post['nvf_general'] )
			? $post['nvf_general']
			: [];

		$bag = get_option( self::OPTION_KEY, [] );
		$bag = is_array( $bag ) ? $bag : [];

		foreach ( self::generalFields() as $fields ) {
			foreach ( $fields as $field ) {
				$id  = $field['id'];
				$raw = $incoming[ $id ] ?? null;
				$bag[ $id ] = self::sanitiseGeneral( $field, $raw );
			}
		}

		update_option( self::OPTION_KEY, $bag, true );
		Logger::info( 'admin.settings_general_saved', [ 'by' => get_current_user_id() ] );
	}

	private static function sanitiseGeneral( array $field, $raw ) {
		switch ( $field['type'] ) {
			case 'date':
				$v = (string) $raw;
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
			case 'number':
				return $raw === '' || $raw === null ? '' : (string) (float) $raw;
			case 'switch':
				return ! empty( $raw ) ? 1 : 0;
			case 'email':
				return sanitize_email( (string) $raw );
			case 'url':
				return esc_url_raw( (string) $raw );
			case 'email_list':
				$list = is_array( $raw ) ? $raw : [];
				$out  = [];
				foreach ( $list as $entry ) {
					$e = sanitize_email( (string) $entry );
					if ( $e !== '' ) {
						$out[] = $e;
					}
				}
				return $out;
			case 'text':
			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	private static function saveStrings( string $tab, array $post ): void {
		$incoming = isset( $post['nvf_strings'] ) && is_array( $post['nvf_strings'] )
			? $post['nvf_strings']
			: [];

		if ( ! $incoming ) {
			return;
		}

		$bag = get_option( self::STRINGS_KEY, [] );
		$bag = is_array( $bag ) ? $bag : [];

		// Process every submitted id whose declaration exists in the registry.
		// The tab parameter is metadata for the redirect only — it does NOT
		// constrain which fields can be written, so search-results edits work
		// from any active tab.
		foreach ( $incoming as $id => $raw ) {
			$entry = StringsRegistry::find( (string) $id );
			if ( ! $entry ) {
				continue; // Unknown id — drop silently to keep the bag clean.
			}
			$clean = self::sanitiseStringFor( $entry['type'], (string) $raw );

			if ( $clean === '' && ! empty( $entry['blank_hides'] ) ) {
				$bag[ $id ] = '';
				continue;
			}
			if ( $clean === '' || $clean === (string) $entry['default'] ) {
				unset( $bag[ $id ] );
				continue;
			}
			$bag[ $id ] = $clean;
		}

		update_option( self::STRINGS_KEY, $bag, true );
		Logger::info( 'admin.strings_saved', [ 'origin_tab' => $tab, 'overrides' => count( $bag ), 'by' => get_current_user_id() ] );
	}

	private static function sanitiseStringFor( string $type, string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		switch ( $type ) {
			case 'html':
				return self::sanitiseHtmlPreservingTokens( $raw );
			case 'textarea':
				return (string) sanitize_textarea_field( $raw );
			case 'text':
			default:
				return (string) sanitize_text_field( $raw );
		}
	}

	private static function sanitiseHtmlPreservingTokens( string $raw ): string {
		$placeholders = [];
		$idx = 0;
		$stashed = preg_replace_callback(
			'/{{\s*(\w+)\s*}}/u',
			static function ( array $m ) use ( &$placeholders, &$idx ) {
				$key = "NVFTOKEN{$idx}NVF";
				$idx++;
				$placeholders[ $key ] = $m[0];
				return $key;
			},
			$raw
		);
		return strtr( (string) wp_kses_post( (string) $stashed ), $placeholders );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Tab + URL helpers
	// ─────────────────────────────────────────────────────────────────────────

	private static function activeTab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		return isset( self::TABS[ $tab ] ) ? $tab : 'general';
	}

	private static function tabUrl( string $tab ): string {
		return add_query_arg( [ 'page' => self::SLUG, 'tab' => $tab ], admin_url( 'admin.php' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Styles + chip-insert JS
	// ─────────────────────────────────────────────────────────────────────────

	private static function renderStyles(): void {
		?>
		<style>
			.nvf-settings__tabs {
				display: flex;
				flex-wrap: wrap;
				row-gap: 6px;
				padding-bottom: 0;
			}
			.nvf-settings__tabs .nav-tab {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				font-size: 13px;
				margin-bottom: 0;       /* row-gap handles vertical spacing now */
			}
			.nvf-settings__tabs .nav-tab .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
				line-height: 16px;
				color: #50575e;
			}
			.nvf-settings__tabs .nav-tab-active .dashicons { color: #036773; }
			.nvf-settings__tabs .nav-tab:hover .dashicons  { color: #036773; }
			.nvf-settings__section {
				margin: 1.5rem 0 0.25rem;
				padding-bottom: 6px;
				border-bottom: 2px solid #036773;
				color: #014B51;
				font-size: 14px;
				letter-spacing: 0.06em;
				text-transform: uppercase;
			}
			.nvf-settings__list input[type=email] { display: block; margin-bottom: 6px; }
			.nvf-settings__field { width: 100%; max-width: 720px; font: inherit; font-size: 14px; padding: 8px 10px; border-radius: 4px; border: 1px solid #c3c4c7; }
			.nvf-settings__field--ta { min-height: 80px; resize: vertical; }
			.nvf-settings__field.is-overridden { border-color: #036773; box-shadow: 0 0 0 1px #036773; }
			.nvf-settings__help {
				margin: 6px 0 0; color: #50575e; font-size: 12px;
				display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
			}
			.nvf-settings__chip {
				display: inline-block; padding: 1px 8px; margin: 0;
				font-family: SFMono-Regular, Menlo, monospace; font-size: 11px; line-height: 1.6;
				background: #f0f6f7; color: #014B51;
				border: 1px solid #c9d6d8; border-radius: 2px;
				cursor: pointer; transition: background 100ms ease;
			}
			.nvf-settings__chip:hover, .nvf-settings__chip:focus { background: #d8e5e7; outline: none; }
			.nvf-settings__chip:focus-visible { box-shadow: 0 0 0 2px #036773; }
			.nvf-settings__reset {
				margin-left: 8px; font-size: 11px; color: #b91c1c;
				cursor: pointer; background: none; border: 0; padding: 0;
			}
			.nvf-settings__reset:hover { text-decoration: underline; }
			.nvf-settings__badge {
				display: inline-block; padding: 1px 6px; margin-left: 6px;
				font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase;
				background: #036773; color: #fff; border-radius: 2px;
				vertical-align: middle;
			}
			.nvf-settings__badge[hidden] { display: none; }
			.nvf-settings__badge--default {
				background: transparent; color: #9ca3af;
				border: 1px solid #d1d5db; font-weight: 600;
			}
			.nvf-settings__badge--hidden {
				background: #fef3c7; color: #92580a; border: 1px solid #f4dba0;
			}

			/* ── Search ───────────────────────────────────────────────────── */
			.nvf-settings__search {
				position: relative;
				margin: 1rem 0;
				max-width: 720px;
			}
			.nvf-settings__search .dashicons-search {
				position: absolute; top: 50%; left: 10px;
				transform: translateY(-50%);
				color: #50575e;
				pointer-events: none;
			}
			#nvf-settings-search {
				width: 100%;
				padding: 10px 36px 10px 36px;
				font-size: 14px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				background: #fff;
			}
			#nvf-settings-search:focus {
				outline: none;
				border-color: #036773;
				box-shadow: 0 0 0 2px rgba(3, 103, 115, 0.18);
			}
			.nvf-settings__search-clear {
				position: absolute; top: 50%; right: 8px;
				transform: translateY(-50%);
				background: transparent; border: 0;
				font-size: 22px; line-height: 1; padding: 0 6px;
				color: #50575e; cursor: pointer;
			}
			.nvf-settings__search-clear:hover { color: #b91c1c; }

			.nvf-settings__search-results {
				margin: 0 0 1.25rem;
				padding: 12px 16px;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				max-width: 1080px;
			}
			.nvf-settings__search-results-summary {
				margin: 0 0 10px;
				font-size: 12px;
				color: #50575e;
				letter-spacing: 0.04em;
			}
			.nvf-settings__search-group {
				margin: 10px 0;
				padding: 8px 0;
				border-top: 1px solid #e5e7eb;
			}
			.nvf-settings__search-group:first-child { border-top: 0; padding-top: 0; }
			.nvf-settings__search-group-heading {
				display: flex; align-items: center; gap: 6px;
				margin: 0 0 6px;
				font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase;
				color: #036773; font-weight: 700;
			}
			.nvf-settings__search-hit {
				display: grid;
				grid-template-columns: 1fr auto;
				gap: 4px 12px;
				padding: 8px 0;
				border-top: 1px dashed #e5e7eb;
			}
			.nvf-settings__search-hit:first-of-type { border-top: 0; }
			.nvf-settings__search-hit-label { font-weight: 600; color: #014B51; }
			.nvf-settings__search-hit-id {
				font-family: SFMono-Regular, Menlo, monospace; font-size: 11px;
				color: #9ca3af;
			}
			.nvf-settings__search-hit-preview {
				grid-column: 1 / -1;
				font-size: 13px; color: #374151;
				white-space: pre-wrap;
				background: #f9fafb;
				border: 1px solid #e5e7eb;
				border-radius: 2px;
				padding: 6px 10px;
				margin-top: 4px;
			}
			.nvf-settings__search-hit-preview mark {
				background: #fef3c7;
				color: inherit;
				padding: 0 1px;
				border-radius: 2px;
			}
			.nvf-settings__search-hit-jump {
				text-decoration: none;
				font-size: 12px; font-weight: 600;
				color: #036773;
				white-space: nowrap;
				align-self: center;
			}
			.nvf-settings__search-hit-jump:hover { text-decoration: underline; }
			.nvf-settings__search-empty {
				margin: 0;
				font-size: 13px;
				color: #6b7280;
			}
		</style>
		<?php
	}

	/**
	 * Builds the URL admins click in a search result to land on the right tab
	 * with the right field focused. Retained for deep-link / bookmarking use.
	 */
	private static function jumpUrl( string $tab, string $id ): string {
		return self::tabUrl( $tab ) . '#' . sanitize_html_class( 'nvf-' . $id );
	}

	/**
	 * Emit a `<template>` per editable string that the search JS clones into
	 * the live search panel. Each template holds the full `<tr>` row markup —
	 * label, input, token chips, reset link, badge — so cloned matches behave
	 * identically to the tab view, including form submission.
	 *
	 * `<template>` content is inert until cloned, so the hidden duplicates
	 * don't double-submit when the tab table is the active view.
	 */
	private static function renderRowTemplates(): void {
		$bag = get_option( self::STRINGS_KEY, [] );
		$bag = is_array( $bag ) ? $bag : [];
		echo '<div class="nvf-settings__row-templates" hidden>';
		foreach ( StringsRegistry::declarations() as $entry ) {
			$id       = $entry['id'];
			$safeId   = sanitize_html_class( str_replace( '.', '-', $id ) );
			$tabLabel = self::TABS[ $entry['group'] ]['label'] ?? $entry['group'];
			$tabIcon  = self::TABS[ $entry['group'] ]['icon']  ?? 'admin-generic';
			?>
			<template id="nvf-row-tpl-<?php echo esc_attr( $safeId ); ?>"
			          data-id="<?php echo esc_attr( $id ); ?>"
			          data-tab="<?php echo esc_attr( $entry['group'] ); ?>"
			          data-tab-label="<?php echo esc_attr( $tabLabel ); ?>"
			          data-tab-icon="<?php echo esc_attr( $tabIcon ); ?>">
				<table><tbody><?php self::renderStringRow( $entry, $bag ); ?></tbody></table>
			</template>
			<?php
		}
		echo '</div>';
	}

	/**
	 * @param array<int,array> $catalog
	 */
	private static function renderSearchScript( array $catalog ): void {
		?>
		<script>
			(function () {
				var CATALOG = <?php echo wp_json_encode( $catalog ); ?>;
				var box      = document.getElementById('nvf-settings-search');
				var clear    = document.querySelector('.nvf-settings__search-clear');
				var results  = document.getElementById('nvf-settings-search-results');
				var summary  = document.getElementById('nvf-settings-search-summary');
				var body     = document.getElementById('nvf-settings-search-body');
				var empty    = document.getElementById('nvf-settings-search-empty');
				var tabsNav  = document.querySelector('.nvf-settings__tabs');
				var tabPane  = document.querySelector('.nvf-settings__tab-content');
				var opsPane  = document.querySelector('.nvf-settings__ops');
				if (!box || !results || !body) return;

				function escapeHtml(s) {
					return String(s)
						.replace(/&/g, '&amp;').replace(/</g, '&lt;')
						.replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
				}

				/** Active tab's inputs must be disabled during search so we don't
				 *  double-submit (the search panel hosts the canonical live copy). */
				function setTabInputsDisabled(disabled) {
					[tabPane, opsPane].forEach(function (pane) {
						if (!pane) return;
						pane.querySelectorAll('input, textarea, select').forEach(function (el) {
							if (disabled) {
								// Don't touch the form's own hidden meta inputs (action, _wpnonce, etc.).
								if (el.type === 'hidden') return;
								el.disabled = true;
								el.dataset.nvfDisabledBySearch = '1';
							} else if (el.dataset.nvfDisabledBySearch === '1') {
								el.disabled = false;
								delete el.dataset.nvfDisabledBySearch;
							}
						});
					});
				}

				/** Tracks which ids currently live in the search results so we can
				 *  add/remove diffs and preserve in-progress edits across keystrokes. */
				var liveIds = new Set();
				var groupContainers = {};

				function clearLiveRows() {
					liveIds.forEach(function (id) {
						var row = body.querySelector('tr[data-nvf-id="' + cssEscape(id) + '"]');
						if (row && row.parentNode) row.parentNode.removeChild(row);
					});
					Object.keys(groupContainers).forEach(function (k) {
						var c = groupContainers[k];
						if (c && c.section && c.section.parentNode) c.section.parentNode.removeChild(c.section);
					});
					liveIds.clear();
					groupContainers = {};
				}

				function cssEscape(s) {
					// CSS.escape polyfill — only the chars we actually emit.
					return String(s).replace(/[".\\\[\]]/g, '\\$&');
				}

				function ensureGroup(tab, tabLabel) {
					if (groupContainers[tab]) return groupContainers[tab].tbody;
					var section = document.createElement('div');
					section.className = 'nvf-settings__search-group';
					section.dataset.tab = tab;
					var heading = document.createElement('div');
					heading.className = 'nvf-settings__search-group-heading';
					heading.innerHTML = '<span class="dashicons dashicons-arrow-right-alt2"></span>' + escapeHtml(tabLabel);
					var table = document.createElement('table');
					table.className = 'form-table';
					var tbody = document.createElement('tbody');
					table.appendChild(tbody);
					section.appendChild(heading);
					section.appendChild(table);
					body.appendChild(section);
					groupContainers[tab] = { section: section, tbody: tbody };
					return tbody;
				}

				function templateFor(id) {
					return document.getElementById('nvf-row-tpl-' + id.replace(/\./g, '-'));
				}

				function cloneRow(id) {
					var tpl = templateFor(id);
					if (!tpl) return null;
					// `<template>` content lives in a sub-document — the actual <tr>
					// is wrapped in a synthetic <table><tbody> by our renderer so HTML
					// parsing keeps it intact. Pull just the <tr>.
					var tr = tpl.content.querySelector('tr');
					if (!tr) return null;
					var clone = tr.cloneNode(true);
					clone.dataset.nvfId = id;
					return clone;
				}

				/** Returns true if the entry matches q on any searchable field. */
				function matches(entry, q) {
					if (!q) return false;
					var hay = (entry.label + ' ' + entry.id + ' ' + entry.default + ' ' + entry.current + ' ' + entry.description).toLowerCase();
					return hay.indexOf(q) !== -1;
				}

				function applySearch(q) {
					q = q.trim().toLowerCase();

					if (!q) {
						// Exit search mode: drop cloned rows + restore tab view.
						clearLiveRows();
						results.hidden = true;
						setTabInputsDisabled(false);
						if (tabPane) tabPane.hidden = false;
						if (opsPane) opsPane.hidden = false;
						if (tabsNav) tabsNav.hidden = false;
						clear.hidden = true;
						return;
					}

					clear.hidden = false;
					if (tabPane) tabPane.hidden = true;
					if (opsPane) opsPane.hidden = true;
					if (tabsNav) tabsNav.hidden = true;
					setTabInputsDisabled(true);
					results.hidden = false;

					var hits = CATALOG.filter(function (e) { return matches(e, q); });

					if (hits.length === 0) {
						clearLiveRows();
						summary.innerHTML = '';
						empty.hidden = false;
						empty.innerHTML = 'No matches for &ldquo;<strong>' + escapeHtml(q) + '</strong>&rdquo;. Try a shorter phrase.';
						return;
					}
					empty.hidden = true;

					var byId = {};
					var order = [];
					hits.forEach(function (e) {
						byId[e.id] = e;
						if (order.indexOf(e.id) === -1) order.push(e.id);
					});

					// Remove ids that no longer match (preserve in-progress edits on the rest).
					Array.from(liveIds).forEach(function (id) {
						if (!byId[id]) {
							var row = body.querySelector('tr[data-nvf-id="' + cssEscape(id) + '"]');
							if (row && row.parentNode) row.parentNode.removeChild(row);
							liveIds.delete(id);
						}
					});

					// Tear down + rebuild group sections so order matches the registry.
					Object.keys(groupContainers).forEach(function (k) {
						var c = groupContainers[k];
						if (c && c.section && c.section.parentNode) c.section.parentNode.removeChild(c.section);
					});
					groupContainers = {};

					// Group hits by tab (preserve registry order via TABS_ORDER).
					var grouped = {};
					var orderTabs = [];
					hits.forEach(function (e) {
						if (!grouped[e.tab]) { grouped[e.tab] = []; orderTabs.push(e.tab); }
						grouped[e.tab].push(e);
					});

					orderTabs.forEach(function (tab) {
						var entries = grouped[tab];
						var tbody = ensureGroup(tab, entries[0].tab_label);
						entries.forEach(function (e) {
							var existing = body.querySelector('tr[data-nvf-id="' + cssEscape(e.id) + '"]');
							if (existing) {
								// Move into the right tbody so groups stay tidy.
								tbody.appendChild(existing);
							} else {
								var row = cloneRow(e.id);
								if (row) {
									tbody.appendChild(row);
									liveIds.add(e.id);
								}
							}
						});
					});

					var tabCount = orderTabs.length;
					summary.innerHTML = hits.length + ' match' + (hits.length === 1 ? '' : 'es')
						+ ' across ' + tabCount + ' tab' + (tabCount === 1 ? '' : 's')
						+ ' — edits below save with the form.';
				}

				var t;
				box.addEventListener('input', function () {
					clearTimeout(t);
					t = setTimeout(function () { applySearch(box.value); }, 80);
				});
				clear.addEventListener('click', function () {
					box.value = '';
					applySearch('');
					box.focus();
				});
				box.addEventListener('keydown', function (e) {
					if (e.key === 'Escape' && box.value) {
						e.preventDefault();
						box.value = '';
						applySearch('');
					}
				});
			})();
		</script>
		<?php
	}

	private static function renderTokenScript(): void {
		?>
		<script>
			(function () {
				var lastFocused = null;
				document.addEventListener('focusin', function (e) {
					if (e.target && e.target.matches('.nvf-settings__field')) {
						lastFocused = e.target;
						maybePrefillDefault(e.target);
					}
				});

				/**
				 * First focus on an empty field auto-populates its value with
				 * the registry default so admins can tweak instead of retyping.
				 *
				 *  - Skipped on `blank_hides` rows (the includes bullets) where
				 *    an empty value carries the "hide this row" meaning.
				 *  - Idempotent: once filled, won't re-fill on subsequent focuses.
				 *  - Saves still drop values that equal the default — so
				 *    prefilling, blurring, and submitting without typing leaves
				 *    no override in the bag.
				 */
				function maybePrefillDefault(el) {
					if (el.dataset.blankHides === '1') return;
					if (el.value !== '') return;
					var def = el.dataset.default || '';
					if (def === '') return;
					el.value = def;
					// Defer cursor placement to the next tick so browsers (Safari)
					// don't override our setSelectionRange when focus arrives.
					var len = def.length;
					setTimeout(function () {
						try { el.setSelectionRange(len, len); } catch (e) { /* type=email etc. */ }
					}, 0);
					// Visually treat as "matches default" until the admin edits.
					el.classList.remove('is-overridden');
					refreshBadges(el);
				}

				/**
				 * Keep the per-row badges in sync with the field's current value.
				 * State machine:
				 *   value === default                            →  "= default"
				 *   value === '' && blank_hides                  →  "Hidden"
				 *   value === '' && !blank_hides                 →  (nothing — using default via placeholder)
				 *   otherwise                                    →  "Overridden"
				 */
				function refreshBadges(el) {
					var row = el.closest('tr');
					if (!row) return;
					var def    = el.dataset.default || '';
					var hides  = el.dataset.blankHides === '1';
					var value  = el.value;

					var override = row.querySelector('.nvf-settings__badge--override');
					var matches  = row.querySelector('.nvf-settings__badge--default');
					var hidden   = row.querySelector('.nvf-settings__badge--hidden');

					var showMatches  = value !== '' && value === def;
					var showHidden   = hides && value === '';
					var showOverride = !showMatches && !showHidden && value !== '';

					if (override) override.hidden = !showOverride;
					if (matches)  matches.hidden  = !showMatches;
					if (hidden)   hidden.hidden   = !showHidden;

					el.classList.toggle('is-overridden', showOverride || showHidden);
				}

				document.addEventListener('input', function (e) {
					if (e.target && e.target.matches('.nvf-settings__field')) {
						refreshBadges(e.target);
					}
				});
				document.addEventListener('click', function (e) {
					var chip = e.target.closest && e.target.closest('.nvf-settings__chip');
					if (!chip) return;
					if (!lastFocused) {
						chip.style.background = '#fde68a';
						setTimeout(function () { chip.style.background = ''; }, 400);
						return;
					}
					var token = chip.dataset.token;
					var el = lastFocused, start = el.selectionStart || 0, end = el.selectionEnd || 0;
					el.value = el.value.slice(0, start) + token + el.value.slice(end);
					var pos = start + token.length;
					el.focus(); el.setSelectionRange(pos, pos);
					el.dispatchEvent(new Event('input', { bubbles: true }));
				});
				document.addEventListener('click', function (e) {
					var reset = e.target.closest && e.target.closest('.nvf-settings__reset');
					if (!reset) return;
					e.preventDefault();
					var row = reset.closest('tr');
					if (!row) return;
					var field = row.querySelector('.nvf-settings__field');
					if (field) {
						field.value = '';
						field.classList.remove('is-overridden');
						refreshBadges(field);
						field.focus();   // triggers prefill via maybePrefillDefault
					}
				});

				// "+ Add address" for email-list general fields.
				document.addEventListener('click', function (e) {
					var add = e.target.closest && e.target.closest('.nvf-settings__list-add');
					if (!add) return;
					var list = add.parentElement;
					var template = list.querySelector('input[type=email]').cloneNode(true);
					template.value = '';
					list.insertBefore(template, add);
				});
			})();
		</script>
		<?php
	}
}
