<?php
/**
 * @var string $participant_name
 * @var string $trip_code
 * @var string $direction
 * @var string $claim_url
 * @var int    $ttl_hours
 * @var string $contact_email
 */
defined( 'ABSPATH' ) || exit;

use NVF\BusBooking\Support\StringRenderer;

$render_ctx = get_defined_vars();
$render_ctx['participant_name'] = $participant_name !== '' ? $participant_name : 'there';
?>
LB SWING SHUTTLE — A SEAT JUST OPENED UP
========================================

<?php echo StringRenderer::renderPlain( 'email.spot_opened.greeting', $render_ctx ); ?>


<?php echo StringRenderer::renderPlain( 'email.spot_opened.lead', $render_ctx ); ?>


Claim URL (valid <?php echo (int) $ttl_hours; ?>h):
<?php echo $claim_url; ?>


If someone else claims it before you, no action needed — you'll keep your place
on the waiting list and we'll email you again when the next seat opens.

—
LB Swing · <?php echo $contact_email ?? 'lbswing.com@gmail.com'; ?>
