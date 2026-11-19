<?php

// Constants WordPress defines at runtime, for static analysis only.
define('ABSPATH', '/tmp/wordpress/');
define('ARRAY_A', 'ARRAY_A');
define('WP_UNINSTALL_PLUGIN', 'audio-to-post/audio-to-post.php');

/**
 * gen-queue's documented registration function (gen-queue 0.1.0), for
 * static analysis only. The plugin calls it only when it exists.
 *
 * @param array<string, mixed> $args
 */
function gen_queue_register_type(string $type, callable $handler, array $args = []): void
{
}
