<?php
/**
 * @var string $participant_name
 * @var string $trip_code
 * @var string $portal_url
 */
defined( 'ABSPATH' ) || exit;
$title   = 'Seat already taken';
$eyebrow = 'LB SWING SHUTTLE · WAITLIST';
require __DIR__ . '/_layout.php';
?>
<?php
$render_ctx = get_defined_vars();
$render_ctx['participant_name'] = $participant_name !== '' ? $participant_name : 'there';
?>
<tr><td style="padding:8px 36px 20px;" colspan="2">
	<p style="margin:0 0 14px;font-size:15px;">
		Hi <?php echo esc_html( $render_ctx['participant_name'] ); ?> —
	</p>
	<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
		<?php echo \NVF\BusBooking\Support\StringRenderer::render( 'email.spot_taken.lead', $render_ctx ); ?>
	</p>
	<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">
		<?php echo \NVF\BusBooking\Support\StringRenderer::render( 'email.spot_taken.closing', $render_ctx ); ?>
	</p>
	<p style="margin:18px 0 0;font-size:13px;color:#5b7479;">
		You can review your booking at any time on the
		<a href="<?php echo esc_url( $portal_url ); ?>" style="color:#036773;">booking page</a>.
	</p>
</td></tr>
<?php require __DIR__ . '/_layout_close.php'; ?>
