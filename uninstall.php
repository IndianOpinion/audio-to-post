<?php

/**
 * Uninstall (section 6.2): runs and their meta, options, the usage
 * table, transcript files and the draft link meta. Audio copies are
 * removed only when the operator ticked "Remove audio copies on
 * uninstall". Draft posts and attachments are kept.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\AudioToPost\WordPress\Uninstaller::run();
