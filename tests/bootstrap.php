<?php
/**
 * Minimal unit-test bootstrap. Loads Composer's autoloader only — the unit
 * suite covers pure logic (no WordPress runtime), so no WP test harness is
 * required. Classes that touch $wpdb/get_post_meta keep that I/O in thin
 * wrappers that the pure methods under test never call.
 */
declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';
