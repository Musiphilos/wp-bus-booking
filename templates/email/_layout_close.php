<?php defined( 'ABSPATH' ) || exit;
$footer_contact = $contact_email ?? \NVF\BusBooking\Support\Settings::contactEmail();
?>
	<tr><td style="padding:18px 36px 28px;border-top:1px solid #d8e5e7;color:#5b7479;font-size:12px;line-height:1.55;" colspan="2">
		LB Swing · <?php echo esc_html( $footer_contact ); ?> · <a href="https://lbswing.com/" style="color:#036773;">lbswing.com</a>
	</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
