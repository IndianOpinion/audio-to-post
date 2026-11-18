<?php

/**
 * Plugin Name:       Audio to post
 * Plugin URI:        https://github.com/IndianOpinion/audio-to-post
 * Description:       Turns an uploaded audio file or a podcast episode into a transcript and then a draft post, keeping speaker turns and timestamps, with chunking for long recordings and a cost estimate the editor confirms first.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       audio-to-post
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AUDIOPOST_VERSION', '0.1.0');
define('AUDIOPOST_FILE', __FILE__);

require_once __DIR__ . '/bootstrap.php';

register_activation_hook(__FILE__, [\IndianOpinion\AudioToPost\WordPress\Hooks::class, 'activate']);
register_deactivation_hook(__FILE__, [\IndianOpinion\AudioToPost\WordPress\Hooks::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \IndianOpinion\AudioToPost\WordPress\UsageLedger::maybeInstall();
    \IndianOpinion\AudioToPost\WordPress\Hooks::register();
});
