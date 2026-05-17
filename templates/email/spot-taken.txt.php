<?php
/**
 * @var string $participant_name
 * @var string $trip_code
 * @var string $portal_url
 * @var string $contact_email
 */
defined( 'ABSPATH' ) || exit;

use NVF\BusBooking\Support\StringRenderer;

$render_ctx = get_defined_vars();
$render_ctx['participant_name'] = $participant_name !== '' ? $participant_name : 'there';
?>
LB SWING SHUTTLE — SEAT TAKEN
=============================

Hi <?php echo $render_ctx['participant_name']; ?>,

<?php echo StringRenderer::renderPlain( 'email.spot_taken.lead', $render_ctx ); ?>


<?php echo StringRenderer::renderPlain( 'email.spot_taken.closing', $render_ctx ); ?>


Booking page: <?php echo $portal_url; ?>

—
LB Swing · <?php echo $contact_email ?? 'lbswing.com@gmail.com'; ?>
