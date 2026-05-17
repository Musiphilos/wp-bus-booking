<?php
/**
 * @var string $admin_event       // 'booking.created' | 'booking.cancelled'
 * @var string $booking_ref
 * @var string $participant_name
 * @var string $participant_email
 * @var string $participant_phone
 * @var array  $legs
 */
defined( 'ABSPATH' ) || exit;
$title   = $admin_event === 'booking.cancelled' ? 'Cancellation received' : 'New booking received';
$eyebrow = 'NVF · ADMIN NOTIFICATION';
require __DIR__ . '/_layout.php';
?>
<?php
$intro_rendered = \NVF\BusBooking\Support\StringRenderer::render( 'email.admin_notification.intro', get_defined_vars() );
?>
<tr><td style="padding:8px 36px 20px;" colspan="2">
	<?php if ( $intro_rendered !== '' ) : ?>
		<p style="margin:0 0 12px;font-size:14px;line-height:1.55;color:#5b7479;">
			<?php echo esc_html( $intro_rendered ); ?>
		</p>
	<?php endif; ?>
	<p style="margin:0 0 12px;font-size:14px;">
		<strong><?php echo esc_html( $booking_ref ); ?></strong> · <?php echo esc_html( $participant_name ?: '(no name)' ); ?>
	</p>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border-collapse:collapse;font-size:13px;">
		<tr><td style="padding:4px 0;color:#5b7479;width:120px;">Email</td><td style="padding:4px 0;"><?php echo esc_html( $participant_email ); ?></td></tr>
		<?php if ( $participant_phone ) : ?>
			<tr><td style="padding:4px 0;color:#5b7479;">Phone</td><td style="padding:4px 0;"><?php echo esc_html( $participant_phone ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( (array) $legs as $leg ) : ?>
			<tr>
				<td style="padding:4px 0;color:#5b7479;"><?php echo esc_html( $leg['direction_label'] ); ?></td>
				<td style="padding:4px 0;">
					<?php echo esc_html( $leg['trip_code'] ); ?> · <?php echo esc_html( $leg['departure_human'] ); ?> · <strong><?php echo esc_html( $leg['status_label'] ); ?></strong>
					<?php if ( $leg['pickup_label'] !== '' ) : ?>
						<div style="color:#5b7479;font-size:12px;">Pickup: <?php echo esc_html( $leg['pickup_label'] ); ?></div>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
	<p style="margin:14px 0 0;font-size:12px;color:#5b7479;">Event: <code><?php echo esc_html( $admin_event ); ?></code></p>
</td></tr>
<?php require __DIR__ . '/_layout_close.php'; ?>
