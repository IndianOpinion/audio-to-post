<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Draft;

/**
 * The JSON shapes the model must answer in (case 63), and a small
 * validator used for every reply, whichever caller produced it.
 */
final class DraftSchema
{
    /** @return array<string, mixed> */
    public static function draft(string $mode): array
    {
        $segIds = ['type' => 'array', 'items' => ['type' => 'string']];
        $schema = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 120],
                'excerpt' => ['type' => 'string', 'maxLength' => 300],
                'sections' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'heading' => ['type' => 'string'],
                        'paragraphs' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => ['text' => ['type' => 'string'], 'segment_ids' => $segIds],
                            'required' => ['text', 'segment_ids'],
                            'additionalProperties' => false,
                        ]],
                    ],
                    'required' => ['heading', 'paragraphs'],
                    'additionalProperties' => false,
                ]],
                'quotes' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['segment_id' => ['type' => 'string'], 'text' => ['type' => 'string']],
                    'required' => ['segment_id', 'text'],
                    'additionalProperties' => false,
                ]],
                'topics' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['title' => ['type' => 'string'], 'segment_id' => ['type' => 'string']],
                    'required' => ['title', 'segment_id'],
                    'additionalProperties' => false,
                ]],
                'speaker_names' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'speaker' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'evidence_segment_id' => ['type' => 'string'],
                    ],
                    'required' => ['speaker', 'name', 'evidence_segment_id'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['title', 'excerpt', 'sections', 'quotes'],
            'additionalProperties' => false,
        ];
        if ($mode === 'show_notes') {
            $schema['required'][] = 'topics';
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    public static function notes(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                'quote_segment_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                'topics' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['title' => ['type' => 'string'], 'segment_id' => ['type' => 'string']],
                    'required' => ['title', 'segment_id'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['key_points', 'quote_segment_ids', 'topics'],
            'additionalProperties' => false,
        ];
    }

    /**
     * True when $filtered keeps every property and requirement of $base
     * (the `audiopost_draft_schema` filter may only extend the schema).
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $filtered
     */
    public static function isSuperset(array $base, array $filtered): bool
    {
        if (($filtered['type'] ?? null) !== 'object' || !is_array($filtered['properties'] ?? null)) {
            return false;
        }
        /** @var array<string, mixed> $bp */
        $bp = $base['properties'];
        foreach (array_keys($bp) as $k) {
            if (!array_key_exists($k, $filtered['properties'])) {
                return false;
            }
        }
        $req = is_array($filtered['required'] ?? null) ? $filtered['required'] : [];
        foreach (is_array($base['required'] ?? null) ? $base['required'] : [] as $r) {
            if (!in_array($r, $req, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate data against the subset of JSON Schema these shapes use:
     * type, properties, required, items, maxLength. Extra keys are
     * allowed here (a filtered schema may add them).
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string> problems; empty when valid
     */
    public static function validate(mixed $data, array $schema, string $path = ''): array
    {
        $type = $schema['type'] ?? null;
        $p = $path === '' ? '/' : $path;
        switch ($type) {
            case 'object':
                if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                    return [$p . ' must be an object'];
                }
                $problems = [];
                foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $r) {
                    if (is_string($r) && !array_key_exists($r, $data)) {
                        $problems[] = $path . '/' . $r . ' is required';
                    }
                }
                foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $k => $sub) {
                    if (array_key_exists($k, $data) && is_array($sub)) {
                        /** @var array<string, mixed> $sub */
                        $problems = array_merge($problems, self::validate($data[$k], $sub, $path . '/' . $k));
                    }
                }

                return $problems;
            case 'array':
                if (!is_array($data) || !array_is_list($data)) {
                    return [$p . ' must be an array'];
                }
                $problems = [];
                if (is_array($schema['items'] ?? null)) {
                    /** @var array<string, mixed> $items */
                    $items = $schema['items'];
                    foreach ($data as $i => $item) {
                        $problems = array_merge($problems, self::validate($item, $items, $path . '/' . $i));
                    }
                }

                return $problems;
            case 'string':
                if (!is_string($data)) {
                    return [$p . ' must be a string'];
                }
                if (isset($schema['maxLength']) && is_int($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
                    return [$p . ' is longer than ' . $schema['maxLength'] . ' characters'];
                }

                return [];
            default:
                return [];
        }
    }
}
