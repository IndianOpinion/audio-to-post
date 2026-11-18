<?php

/**
 * Runs the free half of the pipeline on a generated recording, with no
 * WordPress and no network: probe, plan, estimate, then "transcribe" every
 * chunk with the fake adapter and stitch the results.
 *
 *     php examples/estimate-and-stitch.php [minutes]
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../tests/Support/AudioFixtures.php';

use IndianOpinion\AudioToPost\Adapter\AdapterCapabilities;
use IndianOpinion\AudioToPost\Adapter\ChunkRequest;
use IndianOpinion\AudioToPost\Adapter\FakeAdapter;
use IndianOpinion\AudioToPost\Audio\Prober;
use IndianOpinion\AudioToPost\Estimate\EstimateCalculator;
use IndianOpinion\AudioToPost\Mp3Chunker;
use IndianOpinion\AudioToPost\Plan\ChunkPlanner;
use IndianOpinion\AudioToPost\Stitcher;
use IndianOpinion\AudioToPost\Tests\Support\AudioFixtures;

$minutes = (float) ($argv[1] ?? 61);
$start = microtime(true);
$f = AudioFixtures::mp3($minutes * 60, 64);
$info = (new Prober())->probe($f['path']);
$caps = new AdapterCapabilities(25_000_000, 1200, ['mp3'], diarisation: true, wordTimestamps: true);
$chunker = new Mp3Chunker(AudioFixtures::dir());
$plan = $chunker->locate($f['path'], $info, (new ChunkPlanner())->plan($info, $caps, 600, 10, true));
$estimate = (new EstimateCalculator())->estimate([
    'plan' => $plan, 'caps' => $caps, 'speech_ms' => (int) $info->durationMs, 'duration_exact' => $info->durationExact,
    'upper_bound' => false, 'mode' => 'article', 'language' => 'en', 'known_units' => null, 'words_per_minute' => 150,
    'characters_per_minute' => 250, 'ratio_overrides' => [], 'prompt_chars' => 2500, 'notes_prompt_chars' => 2500,
    'max_output_tokens' => 2000, 'notes_max_output_tokens' => 600, 'single_pass_max_tokens' => 60000, 'block_words' => 1500,
    'price_per_minute' => null, 'price_input' => null, 'price_output' => null, 'currency' => '',
    'free_transcription' => false, 'fingerprint_material' => [],
]);
printf("Recording: %.1f min, %d bytes, duration %s\n", $info->durationMs / 60000, $info->sizeBytes, $info->durationExact ? 'exact' : 'estimated');
printf("Plan: %d chunks; billed %.1f min (overlaps included)\n", $plan->count(), $estimate->billedMinutes());
printf("Draft estimate: about %d input tokens, up to %d output tokens; %s\n", $estimate->tokensIn, $estimate->tokensOut, $estimate->priceLine());

$fake = new FakeAdapter();
$results = [];
foreach ($chunker->split($f['path'], $plan) as $i => $chunkPath) {
    $c = $plan->chunks[$i];
    $results[$i] = $fake->transcribe(new ChunkRequest($chunkPath, 'mp3', $c->startMs, $c->lengthMs(), 'en', 'demo', $i));
    unlink($chunkPath);
}
$out = (new Stitcher())->stitch($plan->chunks, $results, (int) $info->durationMs);
$words = 0;
foreach ($out['transcript']->segments as $s) {
    $words += count(explode(' ', $s->text));
}
printf("Stitched: %d segments, %d speakers, %d words (script has %d), %d seam markers\n", count($out['transcript']->segments), count($out['transcript']->speakers), $words, count($fake->scriptTexts((int) $info->durationMs)), $out['seams']);
printf("Wall time: %.2f s\n", microtime(true) - $start);
AudioFixtures::cleanup();
