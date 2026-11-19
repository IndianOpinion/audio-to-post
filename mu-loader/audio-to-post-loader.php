<?php

/**
 * Plugin Name: Audio to post (must-use loader)
 * Description: Loads audio-to-post from wp-content/mu-plugins/audio-to-post/. Copy this file to wp-content/mu-plugins/.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$audiopost_main = __DIR__ . '/audio-to-post/audio-to-post.php';
if (is_file($audiopost_main)) {
    // Must-use plugins have no activation hook. The plugin installs its
    // table on `plugins_loaded` and schedules its events on `init`.
    require_once $audiopost_main;
}
