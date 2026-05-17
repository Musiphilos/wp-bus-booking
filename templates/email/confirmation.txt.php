<?php
/**
 * @var string      $booking_ref
 * @var string      $participant_name
 * @var string      $participant_email
 * @var float|null  $price_eur
 * @var array       $legs
 * @var string|null $cancellation_deadline
 * @var string      $portal_url
 * @var string      $contact_email
 */
defined( 'ABSPATH' ) || exit;

use NVF\BusBooking\Support\StringRenderer;

$render_ctx = get_defined_vars();
$render_ctx['participant_name'] = $participant_name !== '' ? $participant_name : $participant_email;
if ( $price_eur !== null ) {
	$render_ctx['price_eur'] = number_format( $price_eur, 2, '.', '' );
}
?>
LB SWING SHUTTLE — BOOKING CONFIRMATION
=======================================

<?php echo StringRenderer::renderPlain( 'email.confirmation.greeting', $render_ctx ); ?>


<?php foreach ( (array) $legs as $leg ) : ?>
- <?php echo strtoupper( $leg['direction_label'] ); ?>: <?php echo $leg['trip_code']; ?> · <?php echo $leg['departure_human']; ?> · <?php echo strtoupper( $leg['status_label'] ); ?><?php if ( $leg['pickup_label'] ) : ?>

  Pickup: <?php echo $leg['pickup_label']; ?><?php endif; ?>

<?php endforeach; ?>

<?php if ( $price_eur !== null ) : ?>
<?php echo StringRenderer::renderPlain( 'email.confirmation.on_the_day', $render_ctx ); ?>
<?php else : ?>
On the day: please be at your pickup point 10 minutes early. Fare is collected in cash on the bus.
<?php endif; ?>

<?php if ( $cancellation_deadline ) : ?>
<?php echo StringRenderer::renderPlain( 'email.confirmation.cancellation_reminder', $render_ctx ); ?>
<?php endif; ?>

<?php echo StringRenderer::renderPlain( 'email.confirmation.attachment_note' ); ?>

—
LB Swing · <?php echo $contact_email ?? 'lbswing.com@gmail.com'; ?> · https://lbswing.com/
