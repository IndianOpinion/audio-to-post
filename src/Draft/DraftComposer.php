<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Draft;

use IndianOpinion\AudioToPost\QuoteChecker;
use IndianOpinion\AudioToPost\Segment;
use IndianOpinion\AudioToPost\Transcript;
use IndianOpinion\AudioToPost\Transcript\Timecode;
use IndianOpinion\AudioToPost\Transcript\Turns;

/**
 * Turns a validated model reply (or, in `transcript` mode, the transcript
 * itself) into post fields, blocks and review notes (cases 66 to 69).
 * Times and speakers always come from segments, never from the model.
 */
final class DraftComposer
{
    public function __construct(private readonly Transcript $transcript, private readonly int $turnGapMs = 4000)
    {
    }

    /**
     * @param array<string, mixed>|null $reply   validated model reply; null for transcript mode
     * @param array{audio_url?: ?string, attachment_id?: int, title?: string, append_transcript?: bool, flags?: array<string, mixed>, mappings?: list<array<string, mixed>>} $context
     *
     * @return array{
     *   title: string, excerpt: string,
     *   blocks: list<array{name: string, attrs: array<string, mixed>, html: string}>,
     *   notes: list<array{type: string, message: string, segment_id?: string}>,
     *   suggestions: list<array{speaker: string, name: string, evidence_segment_id: string}>
     * }
     */
    public function compose(string $mode, ?array $reply, array $context): array
    {
        $notes = [];
        $blocks = [];
        $audioUrl = (string) ($context['audio_url'] ?? '');
        $attachmentId = (int) ($context['attachment_id'] ?? 0);
        $linkTimes = $attachmentId > 0 && $audioUrl !== '';
        if ($audioUrl !== '') {
            $blocks[] = BlockBuilder::audio($audioUrl, $attachmentId);
        }
        $suggestions = [];
        $title = (string) ($context['title'] ?? '');
        $excerpt = '';

        if ($mode === 'transcript' || $reply === null) {
            foreach ($this->transcriptBlocks($linkTimes ? $audioUrl : '') as $b) {
                $blocks[] = $b;
            }
        } else {
            $title = trim(strip_tags(self::plain($reply['title'] ?? ''))) ?: $title;
            $excerpt = trim(strip_tags(self::plain($reply['excerpt'] ?? '')));
            foreach (is_array($reply['sections'] ?? null) ? $reply['sections'] : [] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $heading = self::plain($section['heading'] ?? '');
                if ($heading !== '') {
                    $blocks[] = BlockBuilder::heading($heading);
                }
                foreach (is_array($section['paragraphs'] ?? null) ? $section['paragraphs'] : [] as $p) {
                    $text = is_array($p) ? self::plain($p['text'] ?? '') : '';
                    if ($text !== '') {
                        $blocks[] = BlockBuilder::paragraph(BlockBuilder::esc($text));
                    }
                }
            }
            if ($mode === 'show_notes') {
                $items = [];
                foreach (is_array($reply['topics'] ?? null) ? $reply['topics'] : [] as $topic) {
                    if (!is_array($topic)) {
                        continue;
                    }
                    $sid = self::plain($topic['segment_id'] ?? '');
                    $seg = $this->transcript->segment($sid);
                    if ($seg === null) {
                        $notes[] = ['type' => 'topic_removed', 'message' => 'topic cites an unknown segment: ' . self::plain($topic['title'] ?? ''), 'segment_id' => $sid];
                        continue;
                    }
                    $items[] = $this->time($seg, $linkTimes ? $audioUrl : '') . ' ' . BlockBuilder::esc(self::plain($topic['title'] ?? ''));
                }
                if ($items !== []) {
                    $blocks[] = BlockBuilder::heading('Topics');
                    $blocks[] = BlockBuilder::list($items);
                }
            }
            $checker = new QuoteChecker($this->transcript);
            $quoteBlocks = [];
            foreach (is_array($reply['quotes'] ?? null) ? $reply['quotes'] : [] as $q) {
                if (!is_array($q)) {
                    continue;
                }
                $sid = self::plain($q['segment_id'] ?? '');
                $text = self::plain($q['text'] ?? '');
                $ok = $checker->check($text, $sid);
                if ($ok !== true) {
                    $notes[] = ['type' => 'quote_removed', 'message' => $ok . ': "' . $text . '"', 'segment_id' => $sid];
                    continue;
                }
                $seg = $this->transcript->segment($sid);
                if ($seg === null) {
                    continue;
                }
                $cite = BlockBuilder::esc($this->transcript->speakerDisplay($seg->speaker)) . ', ' . $this->time($seg, $linkTimes ? $audioUrl : '');
                $quoteBlocks[] = BlockBuilder::quote($text, $cite);
            }
            foreach ($quoteBlocks as $b) {
                $blocks[] = $b;
            }
            foreach (is_array($reply['speaker_names'] ?? null) ? $reply['speaker_names'] : [] as $sn) {
                if (!is_array($sn)) {
                    continue;
                }
                $suggestions[] = [
                    'speaker' => self::plain($sn['speaker'] ?? ''),
                    'name' => self::plain($sn['name'] ?? ''),
                    'evidence_segment_id' => self::plain($sn['evidence_segment_id'] ?? ''),
                ];
            }
            if (!empty($context['append_transcript'])) {
                $blocks[] = BlockBuilder::details('Transcript', $this->transcriptBlocks($linkTimes ? $audioUrl : ''));
            }
        }
        foreach ($this->flagNotes($context) as $n) {
            $notes[] = $n;
        }
        foreach ($suggestions as $s) {
            $notes[] = ['type' => 'speaker_suggestion', 'message' => sprintf('suggested name for %s: %s (not applied)', $s['speaker'], $s['name']), 'segment_id' => $s['evidence_segment_id']];
        }

        return ['title' => mb_substr($title, 0, 200), 'excerpt' => mb_substr($excerpt, 0, 300), 'blocks' => $blocks, 'notes' => $notes, 'suggestions' => $suggestions];
    }

    /**
     * @return list<array{name: string, attrs: array<string, mixed>, html: string}>
     */
    private function transcriptBlocks(string $audioUrl): array
    {
        $out = [];
        foreach (Turns::build($this->transcript, $this->turnGapMs) as $turn) {
            $first = true;
            foreach ($turn['paragraphs'] as $para) {
                $text = BlockBuilder::esc(implode(' ', array_map(static fn (Segment $s): string => $s->text, $para)));
                $lead = $first
                    ? '<strong>' . BlockBuilder::esc($this->transcript->speakerDisplay($turn['speaker'])) . '</strong> ' . $this->time($para[0], $audioUrl) . ': '
                    : $this->time($para[0], $audioUrl) . ' ';
                $out[] = BlockBuilder::paragraph($lead . $text);
                $first = false;
            }
        }

        return $out;
    }

    private function time(Segment $seg, string $audioUrl): string
    {
        $label = Timecode::short($seg->startMs);
        if ($audioUrl === '') {
            return BlockBuilder::esc($label);
        }

        return '<a href="' . BlockBuilder::escUrl($audioUrl) . '#t=' . intdiv($seg->startMs, 1000) . '">' . BlockBuilder::esc($label) . '</a>';
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<array{type: string, message: string, segment_id?: string}>
     */
    private function flagNotes(array $context): array
    {
        $notes = [];
        $t = $this->transcript;
        foreach ($t->segments as $s) {
            if ($s->hasFlag(Segment::FLAG_SEAM)) {
                $notes[] = ['type' => 'seam', 'message' => 'chunk seam kept both texts; check for repeated words', 'segment_id' => $s->id];
            }
        }
        foreach (is_array($context['mappings'] ?? null) ? $context['mappings'] : [] as $m) {
            if (is_array($m) && !empty($m['low'])) {
                $notes[] = ['type' => 'low_confidence_speaker', 'message' => sprintf('chunk %d: label %s mapped to %s with confidence %.2f', (int) ($m['chunk'] ?? 0), (string) ($m['label'] ?? ''), $t->speakerDisplay((string) ($m['speaker'] ?? '')), (float) ($m['confidence'] ?? 0))];
            }
        }
        if ($t->timestamps === Transcript::TIMESTAMPS_COARSE) {
            $notes[] = ['type' => 'coarse_timestamps', 'message' => 'the provider gave no segment times; timestamps are chunk-level only'];
        }
        if ($t->speakersFlag === Transcript::SPEAKERS_NONE) {
            $notes[] = ['type' => 'missing_speakers', 'message' => 'the provider gave no speaker labels; every segment is Speaker 1'];
        }
        foreach ($t->speakers as $sp) {
            if ($sp->name === '') {
                $notes[] = ['type' => 'unnamed_speaker', 'message' => $sp->label . ' is not named yet'];
            }
        }
        $flags = is_array($context['flags'] ?? null) ? $context['flags'] : [];
        if (!empty($flags['skipped_cues'])) {
            $notes[] = ['type' => 'skipped_cues', 'message' => sprintf('%d malformed cue(s) skipped on import', (int) $flags['skipped_cues'])];
        }

        return $notes;
    }

    /** Model text as plain text with whitespace tidied; callers escape it. */
    public static function plain(mixed $v): string
    {
        if (!is_string($v)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $v));
    }
}
