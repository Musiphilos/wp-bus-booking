<?php
/**
 * @var string $booking_ref
 * @var string $participant_name
 * @var string $participant_email
 * @var array  $legs
 * @var string $portal_url
 * @var string $contact_email
 */
defined( 'ABSPATH' ) || exit;

use NVF\BusBooking\Support\StringRenderer;

$render_ctx = get_defined_vars();
$render_ctx['participant_name'] = $participant_name !== '' ? $participant_name : $participant_email;
?>
LB SWING SHUTTLE — BOOKING UPDATED
==================================

<?php echo StringRenderer::renderPlain( 'email.cancellation.greeting', $render_ctx ); ?>


<?php foreach ( (array) $legs as $leg ) : ?>
- <?php echo strtoupper( $leg['direction_label'] ); ?>: <?php echo $leg['trip_code']; ?> · <?php echo $leg['departure_human']; ?> · <?php echo strtoupper( $leg['status_label'] ); ?>

<?php endforeach; ?>

<?php echo StringRenderer::renderPlain( 'email.cancellation.rebook_line', $render_ctx ); ?>

—
LB Swing · <?php echo $contact_email ?? 'lbswing.com@gmail.com'; ?>
