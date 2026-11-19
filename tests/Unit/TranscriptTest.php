<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Unit;

use IndianOpinion\AudioToPost\QuoteChecker;
use IndianOpinion\AudioToPost\Segment;
use IndianOpinion\AudioToPost\Speaker;
use IndianOpinion\AudioToPost\StateException;
use IndianOpinion\AudioToPost\Transcript;
use IndianOpinion\AudioToPost\Transcript\CueParser;
use IndianOpinion\AudioToPost\Transcript\Editor;
use IndianOpinion\AudioToPost\Transcript\Exporter;
use IndianOpinion\AudioToPost\TranscriptFormatException;
use IndianOpinion\AudioToPost\Word;
use PHPUnit\Framework\TestCase;

final class TranscriptTest extends TestCase
{
    public static function sample(): Transcript
    {
        return new Transcript(
            3_725_000,
            'en',
            Transcript::TIMESTAMPS_FINE,
            Transcript::SPEAKERS_DIARISED,
            [new Speaker('S1', 'Speaker 1', 'Asha Rao'), new Speaker('S2', 'Speaker 2')],
            [
                new Segment('s0001', 0, 4200, 'S1', 'Good morning and welcome.', [new Word(0, 380, 'Good'), new Word(400, 900, 'morning'), new Word(950, 1200, 'and'), new Word(1250, 4200, 'welcome.')], 0.93, 'en'),
                new Segment('s0002', 4500, 9000, 'S2', 'Thank you. It’s good to be <here> & ready.'),
                new Segment('s0003', 9100, 12_000, 'S2', 'We said “the budget will rise” last year.'),
                new Segment('s0004', 3_700_000, 3_725_000, 'S1', 'यह एक परीक्षण है।'),
            ],
        );
    }

    /** T66: invented quotes and unknown ids fail; curly quotes and Devanagari match. */
    public function testQuoteChecker(): void
    {
        $c = new QuoteChecker(self::sample());
        self::assertTrue($c->check('"the budget will rise"', 's0003'));
        self::assertTrue($c->check('It\'s good to be here', 's0002'));
        self::assertTrue($c->check('ready. We said', 's0003'), 'joined with the neighbour by the same speaker');
        self::assertTrue($c->check('यह एक परीक्षण', 's0004'));
        self::assertSame(QuoteChecker::NOT_FOUND, $c->check('the budget will fall', 's0003'));
        self::assertSame(QuoteChecker::NOT_FOUND, $c->check('Good morning', 's0002'), 'different speaker is not a neighbour');
        self::assertSame(QuoteChecker::UNKNOWN_SEGMENT, $c->check('Good morning', 's9999'));
    }

    /** T78: exports match golden files. */
    public function testExportsMatchGoldenFiles(): void
    {
        $t = self::sample();
        foreach (Exporter::FORMATS as $f) {
            $golden = __DIR__ . '/../golden/sample.' . $f;
            $out = Exporter::export($t, $f);
            if (getenv('AUDIOPOST_UPDATE_GOLDEN') === '1') {
                file_put_contents($golden, $out);
            }
            self::assertStringEqualsFile($golden, $out, $f);
        }
        self::assertStringContainsString('<v Asha Rao>Good morning', Exporter::export($t, 'vtt'));
        self::assertStringContainsString('&lt;here&gt; &amp; ready', Exporter::export($t, 'vtt'));
        self::assertStringContainsString("00:00:04,500 --> 00:00:09,000\nSpeaker 2: Thank you.", Exporter::export($t, 'srt'));
        self::assertStringContainsString('[01:01:40] Asha Rao: यह एक परीक्षण है।', Exporter::export($t, 'txt'));
    }

    public function testRoundTrips(): void
    {
        $t = self::sample();
        self::assertEquals($t->toArray(), Transcript::fromJson($t->toJson())->toArray());
        $vtt = CueParser::parse(Exporter::export($t, 'vtt'));
        self::assertSame('vtt', $vtt['format']);
        self::assertSame($t->segments[1]->text, $vtt['transcript']->segments[1]->text);
        self::assertSame('Asha Rao', $vtt['transcript']->speakers[0]->name);
        $srt = CueParser::parse(Exporter::export($t, 'srt'));
        self::assertSame('srt', $srt['format']);
        self::assertSame(4500, $srt['transcript']->segments[1]->startMs);
        self::assertSame('Thank you. It’s good to be <here> & ready.', $srt['transcript']->segments[1]->text);
        $json = $t->toArray();
        $json['custom'] = ['kept' => true];
        self::assertSame(['kept' => true], Transcript::fromArray($json)->toArray()['custom']);
    }

    /** T79: malformed cues are skipped and counted; none readable fails; 11 MB refused. */
    public function testImport(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n<v Ravi>Hello there\n\nbroken cue without times\n\n00:00:05.000 --> 00:00:03.000\nend before start\n\n00:00:06.000 --> 00:00:07.500\nSecond line\n";
        $r = CueParser::parse($vtt);
        self::assertSame(2, $r['skipped']);
        self::assertCount(2, $r['transcript']->segments);
        self::assertSame('Ravi', $r['transcript']->speakers[0]->name);
        try {
            CueParser::parse("WEBVTT\n\nnothing here\n");
            self::fail('expected transcript_unreadable');
        } catch (TranscriptFormatException $e) {
            self::assertSame('transcript_unreadable', $e->errorCode());
        }
        try {
            CueParser::parse(str_repeat('x', 11 * 1024 * 1024));
            self::fail('expected transcript_too_large');
        } catch (TranscriptFormatException $e) {
            self::assertSame('transcript_too_large', $e->errorCode());
        }
        $podcast = CueParser::parse((string) json_encode(['version' => '1.0.0', 'segments' => [['speaker' => 'Host', 'startTime' => 0.5, 'endTime' => 2, 'body' => 'Hi'], ['startTime' => 'x']]]));
        self::assertSame(1, $podcast['skipped']);
        self::assertSame(500, $podcast['transcript']->segments[0]->startMs);
    }

    /** T77: merge, split and text fix. */
    public function testEditorOperations(): void
    {
        $t = self::sample();
        $t = Editor::apply($t, [['op' => 'merge_speakers', 'from' => 'S2', 'into' => 'S1']]);
        self::assertCount(1, $t->speakers);
        self::assertSame(Transcript::SPEAKERS_EDITED, $t->speakersFlag);
        self::assertSame('S1', $t->segments[1]->speaker);
        $t = Editor::apply($t, [['op' => 'split', 'segment' => 's0001', 'word_index' => 2]]);
        self::assertSame('Good morning', $t->segments[0]->text);
        self::assertSame('and welcome.', $t->segments[1]->text);
        self::assertSame(950, $t->segments[1]->startMs);
        self::assertSame('s0005', $t->segments[4]->id);
        $t = Editor::apply($t, [['op' => 'text', 'segment' => 's0003', 'text' => 'Thank you.'], ['op' => 'rename_speaker', 'speaker' => 'S1', 'name' => 'Asha']]);
        self::assertSame('Thank you.', $t->segments[2]->text);
        self::assertSame('Asha', $t->speakers[0]->name);
        $t = Editor::apply($t, [['op' => 'reassign', 'segment' => 's0003', 'speaker' => 'S7']]);
        self::assertSame('S7', $t->segments[2]->speaker);
        self::assertNotNull($t->speaker('S7'));
        $this->expectException(StateException::class);
        Editor::apply($t, [['op' => 'split', 'segment' => 's0003', 'word_index' => 99]]);
    }
}
