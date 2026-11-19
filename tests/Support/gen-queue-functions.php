<?php

/**
 * gen-queue's documented registration function, for the one test that
 * runs in its own process with "gen-queue installed".
 *
 * @param array<string, mixed> $args
 */
function gen_queue_register_type(string $type, callable $handler, array $args = []): void
{
    $GLOBALS['gen_queue_registered'][] = ['type' => $type, 'handler' => $handler, 'args' => $args];
}
