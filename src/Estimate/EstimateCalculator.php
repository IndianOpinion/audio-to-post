<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Estimate;

use IndianOpinion\AudioToPost\Adapter\AdapterCapabilities;
use IndianOpinion\AudioToPost\ChunkPlan;
use IndianOpinion\AudioToPost\Estimate;

/**
 * Computes the pre-run estimate (cases 28 to 31). Pure: no WordPress.
 */
final class EstimateCalculator
{
    public const MODEL_MODES = ['article', 'show_notes'];

    /**
     * @param array{
     *   plan: ?ChunkPlan,
     *   caps: ?AdapterCapabilities,
     *   speech_ms: int,
     *   duration_exact: bool,
     *   upper_bound: bool,
     *   mode: string,
     *   language: string,
     *   known_units?: ?int,
     *   script?: ?string,
     *   words_per_minute: int,
     *   characters_per_minute: int,
     *   ratio_overrides: array<string, float>,
     *   prompt_chars: int,
     *   notes_prompt_chars: int,
     *   max_output_tokens: int,
     *   notes_max_output_tokens: int,
     *   single_pass_max_tokens: int,
     *   block_words: int,
     *   price_per_minute: ?string,
     *   price_input: ?string,
     *   price_output: ?string,
     *   currency: string,
     *   free_transcription: bool,
     *   fingerprint_material: array<string, mixed>,
     * } $in
     */
    public function estimate(array $in): Estimate
    {
        $billed = 0;
        $chunks = 0;
        if (!$in['free_transcription'] && $in['plan'] !== null && $in['caps'] !== null) {
            foreach ($in['plan']->chunks as $c) {
                $billed += $in['caps']->billedSeconds($c->lengthMs());
            }
            $chunks = $in['plan']->count();
        }
        $tokens = self::draftTokens($in);
        $priceConfigured = false;
        $price = null;
        $needTranscription = $billed > 0;
        $needDraft = $tokens['in'] > 0;
        $perMinute = Money::parse($in['price_per_minute']);
        $perIn = Money::parse($in['price_input']);
        $perOut = Money::parse($in['price_output']);
        $complete = (!$needTranscription || $perMinute !== null) && (!$needDraft || ($perIn !== null && $perOut !== null));
        if ($complete && ($needTranscription || $needDraft)) {
            $t = $needTranscription ? Money::times((int) $perMinute, $billed, 60) : 0;
            $di = $needDraft ? Money::times((int) $perIn, $tokens['in'], 1_000_000) : 0;
            $do = $needDraft ? Money::times((int) $perOut, $tokens['out'], 1_000_000) : 0;
            $price = [
                'transcription' => Money::format($t),
                'draft_input' => Money::format($di),
                'draft_output' => Money::format($do),
                'total' => Money::format($t + $di + $do),
                'total_micros' => (string) ($t + $di + $do),
            ];
            $priceConfigured = true;
        }
        $material = [
            'plan' => $in['plan']?->toArray(),
            'billed' => $billed,
            'tokens' => $tokens,
            'mode' => $in['mode'],
            'price' => [$in['price_per_minute'], $in['price_input'], $in['price_output'], $in['currency']],
        ] + $in['fingerprint_material'];
        $fingerprint = hash('sha256', (string) json_encode($material));

        return new Estimate(
            $billed,
            $chunks,
            $tokens['in'],
            $tokens['out'],
            $price,
            $in['currency'],
            $fingerprint,
            $in['duration_exact'],
            $in['upper_bound'],
            $in['mode'],
            $tokens['passes'],
            $priceConfigured,
        );
    }

    /**
     * The token part of case 29.
     *
     * @param array<string, mixed> $in
     *
     * @return array{in: int, out: int, passes: int, units: int, transcript_tokens: int}
     */
    public static function draftTokens(array $in): array
    {
        $mode = (string) $in['mode'];
        if (!in_array($mode, self::MODEL_MODES, true)) {
            return ['in' => 0, 'out' => 0, 'passes' => 0, 'units' => 0, 'transcript_tokens' => 0];
        }
        $language = (string) $in['language'];
        $script = isset($in['script']) && is_string($in['script']) ? $in['script'] : TokenRatios::scriptFor($language);
        /** @var array<string, float> $overrides */
        $overrides = is_array($in['ratio_overrides']) ? $in['ratio_overrides'] : [];
        $ratio = TokenRatios::ratio($language, $overrides, $script);
        $minutes = max(0, (int) $in['speech_ms']) / 60000;
        $known = $in['known_units'] ?? null;
        $units = is_int($known) ? $known : (int) round($minutes * ($script === TokenRatios::CJK ? (int) $in['characters_per_minute'] : (int) $in['words_per_minute']));
        $transcriptTokens = (int) ceil($units * $ratio);
        $overhead = (int) ceil(((int) $in['prompt_chars']) / 4);
        $maxOut = (int) $in['max_output_tokens'];
        if ($transcriptTokens <= (int) $in['single_pass_max_tokens']) {
            return [
                'in' => (int) ceil(($transcriptTokens + $overhead) * 1.1),
                'out' => $maxOut,
                'passes' => 1,
                'units' => $units,
                'transcript_tokens' => $transcriptTokens,
            ];
        }
        $blocks = (int) max(1, ceil($units / max(100, (int) $in['block_words'])));
        $notesOverhead = (int) ceil(((int) $in['notes_prompt_chars']) / 4);
        $notesOut = (int) $in['notes_max_output_tokens'];
        $notesIn = (int) ceil(($transcriptTokens + $blocks * $notesOverhead + ($blocks - 1) * $notesOut) * 1.1);
        $quoteAllowance = (int) min(8000, ceil($transcriptTokens * 0.1));
        $finalIn = (int) ceil(($blocks * $notesOut + $quoteAllowance + $overhead) * 1.1);

        return [
            'in' => $notesIn + $finalIn,
            'out' => $blocks * $notesOut + $maxOut,
            'passes' => $blocks + 1,
            'units' => $units,
            'transcript_tokens' => $transcriptTokens,
        ];
    }
}
