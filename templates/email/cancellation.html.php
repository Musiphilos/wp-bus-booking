<?php
/**
 * @var string $booking_ref
 * @var string $participant_name
 * @var string $participant_email
 * @var array  $legs
 * @var string $portal_url
 */
defined( 'ABSPATH' ) || exit;
$title   = 'Booking cancelled';
$eyebrow = 'LB SWING SHUTTLE · CANCELLATION';
require __DIR__ . '/_layout.php';
?>
<?php
$render_ctx = get_defined_vars();
$render_ctx['participant_name'] = $participant_name !== '' ? $participant_name : $participant_email;
?>
<tr><td style="padding:8px 36px 20px;" colspan="2">
	<p style="margin:0 0 14px;font-size:15px;">
		<?php echo \NVF\BusBooking\Support\StringRenderer::render( 'email.cancellation.greeting', $render_ctx ); ?>
	</p>

	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border-collapse:collapse;border:1px solid #d8e5e7;">
		<?php foreach ( (array) $legs as $leg ) :
			$bg = $leg['status'] === 'cancelled' ? '#fdecec' : ( $leg['status'] === 'waitlist' ? '#fef7e1' : '#ecfdf5' );
			$fg = $leg['status'] === 'cancelled' ? '#881812' : ( $leg['status'] === 'waitlist' ? '#92580a' : '#166534' );
		?>
			<tr>
				<td style="padding:10px 16px;font-size:13px;border-bottom:1px solid #d8e5e7;">
					<strong style="color:#036773;"><?php echo esc_html( $leg['direction_label'] ); ?></strong> · <?php echo esc_html( $leg['trip_code'] ); ?> · <?php echo esc_html( $leg['departure_human'] ); ?>
				</td>
				<td style="padding:10px 16px;text-align:right;border-bottom:1px solid #d8e5e7;">
					<span style="display:inline-block;padding:3px 9px;font-size:10px;letter-spacing:2px;text-transform:uppercase;font-weight:700;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;border:1px solid <?php echo esc_attr( $fg ); ?>;"><?php echo esc_html( $leg['status_label'] ); ?></span>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>

	<p style="margin:16px 0 0;font-size:14px;line-height:1.55;">
		<?php echo \NVF\BusBooking\Support\StringRenderer::render( 'email.cancellation.rebook_line', $render_ctx ); ?>
	</p>
</td></tr>
<?php require __DIR__ . '/_layout_close.php'; ?>
