<?php
/**
 * @var string $booking_ref
 * @var string $participant_name
 * @var string $trip_code
 * @var string $direction
 * @var string $claim_url
 * @var int    $ttl_hours
 * @var array  $legs
 * @var string $contact_email
 */
defined( 'ABSPATH' ) || exit;
$title   = 'A seat just opened up';
$eyebrow = 'LB SWING SHUTTLE · WAITLIST';
require __DIR__ . '/_layout.php';
?>
<?php
$render_ctx = get_defined_vars();
$render_ctx['participant_name'] = $participant_name !== '' ? $participant_name : 'there';
?>
<tr><td style="padding:8px 36px 20px;" colspan="2">
	<p style="margin:0 0 14px;font-size:15px;">
		<?php echo \NVF\BusBooking\Support\StringRenderer::render( 'email.spot_opened.greeting', $render_ctx ); ?>
	</p>

	<p style="margin:0 0 14px;font-size:14px;line-height:1.55;">
		<?php echo \NVF\BusBooking\Support\StringRenderer::render( 'email.spot_opened.lead', $render_ctx ); ?>
	</p>

	<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0 22px;">
		<tr><td>
			<a href="<?php echo esc_url( $claim_url ); ?>"
			   style="display:inline-block;background:#036773;color:#ffffff;text-decoration:none;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;font-size:14px;padding:13px 26px;border-radius:2px;">
				<?php echo esc_html( \NVF\BusBooking\Support\StringRenderer::render( 'email.spot_opened.cta' ) ); ?>
			</a>
		</td></tr>
	</table>

	<p style="margin:0 0 8px;font-size:13px;color:#5b7479;line-height:1.5;">
		If the button doesn't work, paste this URL into your browser:
	</p>
	<p style="margin:0;font-size:12px;word-break:break-all;">
		<a href="<?php echo esc_url( $claim_url ); ?>" style="color:#036773;"><?php echo esc_html( $claim_url ); ?></a>
	</p>

	<p style="margin:20px 0 0;font-size:13px;color:#5b7479;">
		If someone else claims it before you, no action needed — you'll keep your place on the waiting list and we'll email you again when the next seat opens.
	</p>
</td></tr>
<?php require __DIR__ . '/_layout_close.php'; ?>
