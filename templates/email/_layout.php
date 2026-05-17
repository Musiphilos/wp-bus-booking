<?php
/**
 * Shared header/footer for HTML emails. Used via require inside each template.
 *
 * Renders: opening shell, accent header bar, content row, footer signature.
 * The calling template should set $title + $eyebrow then echo its body, then
 * require _layout_close.php.
 *
 * @var string $title
 * @var string $eyebrow
 */
defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $title ); ?></title>
</head>
<body style="margin:0;padding:0;background:#F6F3EB;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#014B51;line-height:1.55;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F3EB;padding:32px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #014B51;border-collapse:collapse;">
	<tr><td style="background:#EBB02B;height:4px;line-height:4px;font-size:0;" colspan="2">&nbsp;</td></tr>
	<tr><td style="padding:28px 36px 8px;" colspan="2">
		<div style="font-size:11px;letter-spacing:4px;text-transform:uppercase;color:#036773;font-weight:700;"><?php echo esc_html( $eyebrow ); ?></div>
		<h1 style="margin:8px 0 0;color:#036773;font-size:24px;line-height:1.2;letter-spacing:-0.005em;"><?php echo esc_html( $title ); ?></h1>
	</td></tr>
