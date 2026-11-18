<?php

/**
 * Plugin Name: Audio to post: site caller example
 * Description: Supplies a model caller through the audiopost_llm_caller filter, so the plugin runs without llm-json-php. Replace the body of the callable with your own client.
 */

declare(strict_types=1);

add_filter('audiopost_llm_caller', static function ($caller, array $context) {
    return static function (array $request, array $context) {
        // $request: prompt, input, schema, schema_name, max_output_tokens.
        // Call your own client here and return the decoded JSON reply plus usage.
        $reply = my_site_model_call($request['prompt'] . "\n\n" . $request['input'], $request['schema'], $request['max_output_tokens']);
        if ($reply === null) {
            return new WP_Error('model_failed', 'The site model call failed.');
        }

        return $reply['data'] + ['tokens_in' => $reply['tokens_in'], 'tokens_out' => $reply['tokens_out']];
    };
}, 10, 2);
