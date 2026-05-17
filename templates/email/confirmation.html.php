<?php
/**
 * @var string      $booking_ref
 * @var string      $participant_name
 * @var string      $participant_email
 * @var float|null  $price_eur
 * @var array       $legs
 * @var string|null $cancellation_deadline
 * @var string      $portal_url
 */
defined( 'ABSPATH' ) || exit;
$title   = 'Your shuttle booking';
$eyebrow = 'LB SWING SHUTTLE · CONFIRMATION';
require __DIR__ . '/_layout.php';
?>
<?php
$render_ctx = get_defined_vars();
// Booking-context emails get a "display name" so token substitution doesn't
// produce "Hi —" when the participant didn't enter a name on registration.
$render_ctx['participant_name'] = $participant_name !== '' ? $participant_name : $participant_email;
?>
<tr><td style="padding:8px 36px 20px;" colspan="2">
	<p style="margin:0 0 14px;font-size:15px;">
		<?php echo \NVF\BusBooking\Support\StringRenderer::render( 'email.confirmation.greeting', $render_ctx ); ?>
	</p>

	<?php foreach ( (array) $legs as $leg ) :
		$isWaitlist = $leg['status'] === 'waitlist';
		$bg = $isWaitlist ? '#fef7e1' : '#ecfdf5';
		$fg = $isWaitlist ? '#92580a' : '#166534';
	?>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border-collapse:collapse;border:1px solid #d8e5e7;">
			<tr><td style="padding:14px 16px;background:#F6F3EB;">
				<div style="font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#036773;font-weight:700;"><?php echo esc_html( $leg['direction_label'] ); ?></div>
				<div style="font-size:18px;font-weight:700;margin-top:3px;color:#014B51;"><?php echo esc_html( $leg['trip_code'] ); ?> · <?php echo esc_html( $leg['departure_human'] ); ?></div>
				<div style="margin-top:8px;">
					<span style="display:inline-block;padding:3px 9px;font-size:10px;letter-spacing:2px;text-transform:uppercase;font-weight:700;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;border:1px solid <?php echo esc_attr( $fg ); ?>;">
						<?php echo esc_html( $leg['status_label'] ); ?>
					</span>
				</div>
			</td></tr>
			<?php if ( $leg['pickup_label'] !== '' ) : ?>
				<tr><td style="padding:12px 16px;font-size:13px;">
					<strong style="color:#036773;">Pickup:</strong> <?php echo esc_html( $leg['pickup_label'] ); ?>
				</td></tr>
			<?php endif; ?>
		</table>
	<?php endforeach; ?>

	<p style="margin:18px 0 0;font-size:14px;line-height:1.55;">
		<?php
		if ( $price_eur !== null ) {
			$render_ctx['price_eur'] = number_format( $price_eur, 2, '.', '' );
			echo \NVF\BusBooking\Support\StringRenderer::render( 'email.confirmation.on_the_day', $render_ctx );
		} else {
			echo '<strong style="color:#036773;">' . esc_html__( 'On the day:', 'nvf-bus-booking' ) . '</strong> '
				. esc_html__( 'please be at your pickup point 10 minutes early. Fare is collected in cash on the bus.', 'nvf-bus-booking' );
		}
		?>
	</p>

	<?php if ( $cancellation_deadline ) : ?>
		<p style="margin:10px 0 0;font-size:14px;">
			<?php echo \NVF\BusBooking\Support\StringRenderer::render( 'email.confirmation.cancellation_reminder', $render_ctx ); ?>
		</p>
	<?php endif; ?>

	<p style="margin:18px 0 0;font-size:13px;color:#5b7479;">
		<?php echo esc_html( \NVF\BusBooking\Support\StringRenderer::render( 'email.confirmation.attachment_note' ) ); ?>
	</p>
</td></tr>
<?php require __DIR__ . '/_layout_close.php'; ?>
