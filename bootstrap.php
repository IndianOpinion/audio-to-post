<?php

/**
 * Loads the plugin's classes without Composer, and the Composer autoloader
 * when there is one (for indianopinion/llm-json-php).
 */

declare(strict_types=1);

if (!defined('AUDIOPOST_DIR')) {
    define('AUDIOPOST_DIR', __DIR__);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\AudioToPost\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = AUDIOPOST_DIR . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (is_file(AUDIOPOST_DIR . '/vendor/autoload.php')) {
    require_once AUDIOPOST_DIR . '/vendor/autoload.php';
}

require_once AUDIOPOST_DIR . '/src/functions.php';
