<?php
/**
 * Magic-link email template.
 *
 * @var string $url       The single-use verification URL.
 * @var int    $ttlHours  Link validity in hours.
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>LB Swing — Bus booking</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111418;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;">
	<tr>
		<td align="center">
			<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;box-shadow:0 4px 12px rgba(15,23,42,0.06);">
				<tr>
					<td style="padding:32px 36px 16px;">
						<h1 style="margin:0 0 12px;font-size:20px;letter-spacing:-0.01em;">LB Swing — Shuttle bus</h1>
						<p style="margin:0;color:#475569;line-height:1.55;">
							<?php echo esc_html( \NVF\BusBooking\Support\StringRenderer::render( 'email.magic_link.body', [ 'ttl_hours' => (int) $ttlHours ] ) ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<td style="padding:8px 36px 32px;" align="center">
						<a href="<?php echo esc_url( $url ); ?>"
						   style="display:inline-block;background:#0b6fda;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:8px;">
							<?php echo esc_html__( 'Open booking page', 'nvf-bus-booking' ); ?>
						</a>
					</td>
				</tr>
				<tr>
					<td style="padding:0 36px 32px;">
						<p style="margin:0 0 8px;color:#64748b;font-size:13px;line-height:1.5;">
							<?php echo esc_html__( "If the button doesn't work, paste this URL into your browser:", 'nvf-bus-booking' ); ?>
						</p>
						<p style="margin:0;font-size:12px;word-break:break-all;">
							<a href="<?php echo esc_url( $url ); ?>" style="color:#0b6fda;"><?php echo esc_html( $url ); ?></a>
						</p>
					</td>
				</tr>
				<tr>
					<td style="padding:16px 36px 28px;border-top:1px solid #e5e7eb;color:#94a3b8;font-size:12px;line-height:1.5;">
						<?php echo esc_html__( "If you didn't request this link, you can safely ignore this email.", 'nvf-bus-booking' ); ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
