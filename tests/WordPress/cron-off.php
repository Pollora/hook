<?php

declare(strict_types=1);

/**
 * Turns WP-Cron off for the process that includes it, before WordPress loads.
 *
 * The WordPress the harness job installs already defines DISABLE_WP_CRON in wp-config.php, and a second define() makes
 * wp-config.php print "Constant DISABLE_WP_CRON already defined": into the output of the processes the tests start
 * and compare. A WordPress installed by hand (see load.php) has no such line, so the constant is defined here then.
 */
$wpConfig = (getenv('WP_PATH') ?: dirname(__DIR__, 2).'/.wordpress').'/wp-config.php';

if (! is_file($wpConfig) || ! str_contains((string) file_get_contents($wpConfig), 'DISABLE_WP_CRON')) {
    defined('DISABLE_WP_CRON') || define('DISABLE_WP_CRON', true);
}

unset($wpConfig);
