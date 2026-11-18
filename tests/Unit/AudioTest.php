<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Unit;

use IndianOpinion\AudioToPost\Audio\FormatDetector;
use IndianOpinion\AudioToPost\Audio\Mp3Reader;
use IndianOpinion\AudioToPost\Audio\Prober;
use IndianOpinion\AudioToPost\Audio\WavReader;
use IndianOpinion\AudioToPost\AudioException;
use IndianOpinion\AudioToPost\Chunk;
use IndianOpinion\AudioToPost\ChunkPlan;
use IndianOpinion\AudioToPost\Mp3Chunker;
use IndianOpinion\AudioToPost\SourceException;
use IndianOpinion\AudioToPost\Tests\Support\AudioFixtures;
use IndianOpinion\AudioToPost\WavChunker;
use PHPUnit\Framework\TestCase;

final class AudioTest extends TestCase
{
    protected function tearDown(): void
    {
        AudioFixtures::cleanup();
    }

    /** T17: the probe records every listed field. */
    public function testProbeRecordsEveryField(): void
    {
        $f = AudioFixtures::mp3(5.0, 128, [], true, false, true);
        $info = (new Prober())->probe($f['path'], 'audio/mpeg');
        self::assertSame('mp3', $info->format);
        self::assertSame('mp3', $info->codec);
        self::assertSame(44100, $info->sampleRate);
        self::assertSame(2, $info->channels);
        self::assertSame(128000, $info->bitrate);
        self::assertFalse($info->variableBitrate);
        self::assertSame($f['duration_ms'], $info->durationMs);
        self::assertTrue($info->durationExact);
        self::assertSame(filesize($f['path']), $info->sizeBytes);
        self::assertSame(hash_file('sha256', $f['path']), $info->sha256);
        $a = $info->toArray();
        foreach (['format', 'codec', 'sample_rate', 'channels', 'bitrate', 'variable_bitrate', 'duration_ms', 'duration_known', 'duration_exact', 'size_bytes', 'sha256'] as $k) {
            self::assertArrayHasKey($k, $a);
        }
    }

    /** T18: VBR MP3 without a Xing header: frame count is exact within 30 ms. */
    public function testVbrWithoutXingIsCountedExactly(): void
    {
        $f = AudioFixtures::mp3(20.0, 128, [64, 128, 320, 96]);
        $size = filesize($f['path']);
        // The core reader estimates from the first frame's bitrate.
        $estimate = static fn (string $p): array => ['length' => round($size * 8 / 64000), 'bitrate' => 64000, 'bitrate_mode' => 'cbr'];
        $info = (new Prober($estimate))->probe($f['path']);
        self::assertTrue($info->variableBitrate);
        self::assertTrue($info->durationExact);
        self::assertEqualsWithDelta($f['duration_ms'], (int) $info->durationMs, 30);
        self::assertNotEqualsWithDelta($estimate($f['path'])['length'] * 1000, (int) $info->durationMs, 1000);
    }

    public function testXingFrameIsSkippedAndCpuCapFallsBack(): void
    {
        $f = AudioFixtures::mp3(10.0, 128, [], false, true);
        $scan = Mp3Reader::scan($f['path']);
        self::assertSame($f['frames'], $scan['frames']);
        self::assertSame($f['frames'], $scan['info_frames']);
        $capped = (new Prober(null, 0.0))->probe($f['path']);
        self::assertFalse($capped->durationExact);
        self::assertEqualsWithDelta($f['duration_ms'], (int) $capped->durationMs, 30);
    }

    /** T22: each MP3 chunk starts on a frame sync and holds only valid frames. */
    public function testMp3ChunksAreValidFrames(): void
    {
        $f = AudioFixtures::mp3(30.0, 128, [], true, true, true);
        $info = (new Prober())->probe($f['path']);
        $plan = new ChunkPlan('mp3', [new Chunk(0, 0, 12000), new Chunk(1, 10000, 22000), new Chunk(2, 20000, (int) $info->durationMs)]);
        $chunker = new Mp3Chunker(AudioFixtures::dir());
        self::assertTrue($chunker->supports($info));
        $plan = $chunker->locate($f['path'], $info, $plan);
        $total = 0;
        foreach ($chunker->split($f['path'], $plan) as $i => $path) {
            $data = (string) file_get_contents($path);
            self::assertNotSame('ID3', substr($data, 0, 3));
            $pos = 0;
            $frames = 0;
            while ($pos < strlen($data)) {
                $h = Mp3Reader::parseHeader(substr($data, $pos, 4));
                self::assertNotNull($h, "chunk $i frame at $pos");
                self::assertStringNotContainsString('Xing', substr($data, $pos, 64));
                $pos += $h['length'];
                $frames++;
            }
            self::assertSame(strlen($data), $pos);
            $c = $plan->chunks[$i];
            self::assertEqualsWithDelta($c->lengthMs(), intdiv($frames * 1152 * 1000, 44100), 27);
            $total += $frames;
            unlink($path);
        }
        self::assertGreaterThan($f['frames'], $total, 'overlaps repeat frames');
        self::assertSame($plan->chunks[0]->endMs - $plan->chunks[1]->startMs, $plan->chunks[0]->overlapAfterMs);
        self::assertEqualsWithDelta(2000, $plan->chunks[0]->overlapAfterMs, 60);
    }

    /** T22: each WAV chunk has its own correct header and data size. */
    public function testWavChunksHaveCorrectHeaders(): void
    {
        $path = AudioFixtures::wav(10.0);
        $info = (new Prober())->probe($path);
        self::assertSame(10000, $info->durationMs);
        self::assertSame('pcm', $info->codec);
        $chunker = new WavChunker(AudioFixtures::dir());
        $plan = $chunker->locate($path, $info, new ChunkPlan('wav', [new Chunk(0, 0, 6000), new Chunk(1, 5000, 10000)]));
        foreach ($chunker->split($path, $plan) as $i => $chunkPath) {
            $w = WavReader::read($chunkPath);
            self::assertSame(44, $w['data_offset']);
            self::assertSame(filesize($chunkPath) - 44, $w['data_size']);
            self::assertSame(8000, $w['sample_rate']);
            self::assertSame($plan->chunks[$i]->lengthMs() * 16, $w['data_size']);
            self::assertSame('RIFF', substr((string) file_get_contents($chunkPath, false, null, 0, 4), 0, 4));
            unlink($chunkPath);
        }
    }

    /** T12: an HTML body is not audio; an RSS body is a feed. */
    public function testHtmlAndRssAreRecognised(): void
    {
        $html = AudioFixtures::bytes("<!DOCTYPE html>\n<html><head><title>Episode</title>", 200, 'mp3');
        try {
            FormatDetector::detect($html, 'audio/mpeg');
            self::fail('expected not_audio');
        } catch (SourceException $e) {
            self::assertSame('not_audio', $e->errorCode());
            self::assertSame(FormatDetector::NOT_AUDIO_MESSAGE, $e->getMessage());
        }
        $rss = AudioFixtures::bytes('<?xml version="1.0"?><rss version="2.0"><channel>', 200, 'mp3');
        try {
            FormatDetector::detect($rss);
            self::fail('expected feed_url');
        } catch (SourceException $e) {
            self::assertSame('feed_url', $e->errorCode());
        }
    }

    public function testMagicBytesWinOverTheHeader(): void
    {
        $f = AudioFixtures::mp3(2.0);
        self::assertSame('mp3', FormatDetector::detect($f['path'], 'text/html'));
        self::assertSame('ogg', FormatDetector::sniff('OggS' . str_repeat("\0", 20)));
        self::assertSame('flac', FormatDetector::sniff('fLaC' . str_repeat("\0", 20)));
        self::assertSame('webm', FormatDetector::sniff("\x1A\x45\xDF\xA3" . str_repeat("\0", 20)));
    }

    /** T14: MP4 with a video track is refused; audio-only M4A is accepted. */
    public function testVideoIsRefusedAudioOnlyMp4Accepted(): void
    {
        try {
            FormatDetector::detect(AudioFixtures::mp4(['vide', 'soun']));
            self::fail('expected video_not_supported');
        } catch (AudioException $e) {
            self::assertSame('video_not_supported', $e->errorCode());
        }
        self::assertSame('m4a', FormatDetector::detect(AudioFixtures::mp4(['soun'])));
        $unknown = AudioFixtures::bytes('garbage', 100);
        try {
            FormatDetector::detect($unknown, 'video/mp4');
            self::fail('expected video_not_supported');
        } catch (AudioException $e) {
            self::assertSame('video_not_supported', $e->errorCode());
        }
        $webm = AudioFixtures::bytes("\x1A\x45\xDF\xA3" . "\x16\x54\xAE\x6B" . "\x86\x85V_VP9", 200, 'webm');
        self::assertTrue(FormatDetector::webmHasVideo($webm));
    }

    /** T15: an empty file fails without a call. */
    public function testEmptyAudio(): void
    {
        $empty = AudioFixtures::dir() . '/empty.mp3';
        file_put_contents($empty, '');
        try {
            (new Prober())->probe($empty);
            self::fail('expected empty_audio');
        } catch (AudioException $e) {
            self::assertSame('empty_audio', $e->errorCode());
        }
        $short = AudioFixtures::mp3(0.5);
        $this->expectException(AudioException::class);
        (new Prober())->probe($short['path']);
    }

    /** T16: an unmeasurable recording has no duration. */
    public function testUnmeasurableDuration(): void
    {
        $flac = AudioFixtures::bytes('fLaC', 5000, 'flac');
        $info = (new Prober(static fn (): ?array => null))->probe($flac);
        self::assertNull($info->durationMs);
        self::assertFalse($info->durationKnown());
    }
}
