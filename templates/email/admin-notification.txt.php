<?php
/**
 * @var string $admin_event
 * @var string $booking_ref
 * @var string $participant_name
 * @var string $participant_email
 * @var string $participant_phone
 * @var array  $legs
 */
defined( 'ABSPATH' ) || exit;
?>
NVF · ADMIN NOTIFICATION — <?php echo $admin_event; ?>

<?php $intro = \NVF\BusBooking\Support\StringRenderer::renderPlain( 'email.admin_notification.intro', get_defined_vars() ); if ( $intro !== '' ) : ?><?php echo $intro; ?>

<?php endif; ?><?php echo $booking_ref; ?> · <?php echo $participant_name ?: '(no name)'; ?>

Email: <?php echo $participant_email; ?>
<?php if ( $participant_phone ) : ?>Phone: <?php echo $participant_phone; ?><?php endif; ?>

<?php foreach ( (array) $legs as $leg ) : ?>
<?php echo strtoupper( $leg['direction_label'] ); ?>: <?php echo $leg['trip_code']; ?> · <?php echo $leg['departure_human']; ?> · <?php echo strtoupper( $leg['status_label'] ); ?><?php if ( $leg['pickup_label'] ) : ?>

  Pickup: <?php echo $leg['pickup_label']; ?><?php endif; ?>

<?php endforeach; ?>

—
NVF Bus Booking
