<?php
/**
 * PDF ticket layout. Inline CSS only — Dompdf is picky about stylesheets.
 *
 * @var string      $booking_ref
 * @var string      $participant_name
 * @var string      $participant_email
 * @var string      $participant_phone
 * @var float|null  $price_eur
 * @var array       $legs
 * @var string|null $cancellation_deadline
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>LB Swing — Bus Booking · <?php echo esc_html( $booking_ref ); ?></title>
</head>
<body style="font-family: DejaVu Sans, sans-serif; color: #014B51; margin: 0; padding: 0;">

<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
	<tr>
		<td style="background: #036773; color: #ffffff; padding: 28px 36px;">
			<div style="font-size: 11px; letter-spacing: 4px; text-transform: uppercase; opacity: 0.85;">LB SWING SHUTTLE</div>
			<div style="font-size: 28px; font-weight: bold; margin-top: 4px;">Bus Booking</div>
		</td>
		<td style="background: #036773; color: #ffffff; padding: 28px 36px; text-align: right;">
			<div style="font-size: 11px; letter-spacing: 4px; text-transform: uppercase; opacity: 0.85;">Reference</div>
			<div style="font-family: DejaVu Sans Mono, monospace; font-size: 22px; font-weight: bold; margin-top: 4px;"><?php echo esc_html( $booking_ref ); ?></div>
		</td>
	</tr>
	<tr>
		<td colspan="2" style="background: #EBB02B; height: 4px; line-height: 4px; font-size: 0;">&nbsp;</td>
	</tr>
</table>

<div style="padding: 32px 36px;">

	<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 26px; border-collapse: collapse;">
		<tr>
			<td style="width: 50%; vertical-align: top;">
				<div style="font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: #5b7479;"><?php echo esc_html( \NVF\BusBooking\Support\StringRenderer::render( 'pdf.passenger_label' ) ); ?></div>
				<div style="font-size: 18px; font-weight: bold; margin-top: 4px;"><?php echo esc_html( $participant_name ?: $participant_email ); ?></div>
				<div style="font-size: 12px; color: #5b7479; margin-top: 2px;"><?php echo esc_html( $participant_email ); ?><?php if ( $participant_phone ) : ?> &nbsp;·&nbsp; <?php echo esc_html( $participant_phone ); ?><?php endif; ?></div>
			</td>
			<td style="width: 50%; vertical-align: top; text-align: right;">
				<?php if ( $price_eur !== null ) : ?>
					<div style="font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: #5b7479;">Cash on board · <?php echo esc_html( $price_label ?? '' ); ?></div>
					<div style="font-size: 18px; font-weight: bold; margin-top: 4px;"><?php echo esc_html( number_format( $price_eur, 2, '.', '' ) ); ?> €</div>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<?php foreach ( (array) $legs as $leg ) :
		$isWaitlist = $leg['status'] === 'waitlist';
		$isCancel   = $leg['status'] === 'cancelled';
		$statusBg   = $isCancel ? '#fdecec' : ( $isWaitlist ? '#fef7e1' : '#ecfdf5' );
		$statusFg   = $isCancel ? '#881812' : ( $isWaitlist ? '#92580a' : '#166534' );
	?>
		<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin-bottom: 18px; border: 1px solid #d8e5e7;">
			<tr>
				<td style="padding: 18px 22px; background: #F6F3EB; border-bottom: 1px solid #d8e5e7;" colspan="2">
					<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
						<tr>
							<td>
								<div style="font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: #036773; font-weight: bold;"><?php echo esc_html( $leg['direction_label'] ); ?></div>
								<div style="font-size: 20px; font-weight: bold; margin-top: 4px; color: #014B51;"><?php echo esc_html( $leg['trip_code'] ); ?></div>
								<div style="font-size: 13px; color: #5b7479; margin-top: 2px;"><?php echo esc_html( $leg['departure_human'] ); ?></div>
							</td>
							<td style="text-align: right;">
								<span style="display: inline-block; padding: 4px 10px; font-size: 10px; letter-spacing: 3px; text-transform: uppercase; font-weight: bold; background: <?php echo esc_attr( $statusBg ); ?>; color: <?php echo esc_attr( $statusFg ); ?>; border: 1px solid <?php echo esc_attr( $statusFg ); ?>;">
									<?php echo esc_html( $leg['status_label'] ); ?>
								</span>
							</td>
						</tr>
					</table>
				</td>
			</tr>
			<?php if ( $leg['direction'] === 'inbound' && $leg['pickup_label'] !== '' ) : ?>
				<tr>
					<td style="padding: 14px 22px; border-bottom: 1px solid #d8e5e7;">
						<div style="font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: #5b7479;">Pickup</div>
						<div style="font-size: 14px; margin-top: 3px;"><?php echo esc_html( $leg['pickup_label'] ); ?></div>
					</td>
					<td>&nbsp;</td>
				</tr>
			<?php endif; ?>
			<?php if ( ! empty( $leg['stops'] ) ) : ?>
				<tr>
					<td style="padding: 14px 22px;" colspan="2">
						<div style="font-size: 9px; letter-spacing: 2px; text-transform: uppercase; color: #5b7479; margin-bottom: 6px;">Itinerary</div>
						<?php foreach ( $leg['stops'] as $stop ) : ?>
							<div style="font-size: 13px; margin: 2px 0;">
								<span style="display: inline-block; width: 60px; font-family: DejaVu Sans Mono, monospace; color: #036773;"><?php echo esc_html( $stop['time'] ); ?></span>
								<?php echo esc_html( $stop['label'] ); ?>
							</div>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
	<?php endforeach; ?>

	<table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 8px; border-collapse: collapse; border-top: 1px solid #d8e5e7; padding-top: 16px;">
		<tr>
			<td style="padding-top: 18px; font-size: 11px; color: #5b7479; line-height: 1.6;">
				<?php echo esc_html( \NVF\BusBooking\Support\StringRenderer::render( 'pdf.on_the_day' ) ); ?>
				<?php if ( $cancellation_deadline ) : ?>
					<br><?php echo esc_html( \NVF\BusBooking\Support\StringRenderer::render( 'pdf.cancellation_reminder', [
						'cancellation_deadline' => $cancellation_deadline,
						'contact_email'         => $contact_email ?? 'lbswing.com@gmail.com',
					] ) ); ?>
				<?php endif; ?>
			</td>
		</tr>
	</table>

</div>
</body>
</html>
