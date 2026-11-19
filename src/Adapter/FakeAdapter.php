<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Adapter;

use IndianOpinion\AudioToPost\ProviderException;
use IndianOpinion\AudioToPost\Segment;
use IndianOpinion\AudioToPost\Word;

/**
 * A deterministic adapter for tests and local trials (id `fake`).
 *
 * It "hears" a script of numbered words (`w1`, `w2`, ...) spoken every
 * `wordEveryMs` by speakers who take turns every `turnWords` words. A
 * chunk returns the words that lie wholly inside it, so a word cut by a
 * chunk boundary is lost in that chunk, as with a real provider. Speaker
 * labels are permuted per chunk so stitching has to map them. Failures can
 * be queued per call.
 *
 * Only registered when WP_DEBUG is true, or by tests.
 */
final class FakeAdapter implements TranscriptionAdapter
{
    /** @var list<ChunkRequest> */
    public array $calls = [];
    /** @var list<string> */
    public array $polls = [];
    /** @var list<string|\Throwable|null> queued outcomes: null = normal, '429', '413', '500', '401', '402', 'timeout', 'refusal', 'empty' */
    private array $failures = [];
    /** @var array<string, array{request: ChunkRequest, polls: int}> */
    private array $jobs = [];
    /** @var (\Closure(ChunkRequest): void)|null called while a call is "in flight" (tests) */
    public ?\Closure $during = null;

    /**
     * @param 'words'|'segments'|'none' $timing
     */
    public function __construct(
        private ?AdapterCapabilities $caps = null,
        public int $wordEveryMs = 400,
        public int $wordLengthMs = 300,
        public int $turnWords = 20,
        public int $speakers = 2,
        public bool $diarise = true,
        public string $timing = 'words',
        public bool $permuteLabels = true,
        public ?string $language = 'en',
        public int $pollsBeforeDone = 1,
        public bool $empty = false,
        public ?int $retryAfter = null,
    ) {
    }

    public function id(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake adapter (tests only)';
    }

    public function capabilities(): AdapterCapabilities
    {
        return $this->caps ?? new AdapterCapabilities(
            25_000_000,
            1200,
            ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'webm'],
            diarisation: $this->diarise,
            wordTimestamps: $this->timing === 'words',
            segmentTimestamps: $this->timing !== 'none',
            languageHint: true,
            typicalSecondsPerAudioMinute: 0.0,
        );
    }

    public function withCapabilities(AdapterCapabilities $caps): self
    {
        $this->caps = $caps;

        return $this;
    }

    /**
     * Queue outcomes for the next calls, in order.
     */
    public function failNext(string|\Throwable|null ...$outcomes): self
    {
        foreach ($outcomes as $o) {
            $this->failures[] = $o;
        }

        return $this;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function configurationProblems(): array
    {
        return [];
    }

    public function transcribe(ChunkRequest $request): ChunkResult
    {
        $this->calls[] = $request;
        $this->maybeFail();
        if ($this->during !== null) {
            ($this->during)($request);
        }

        return $this->hear($request);
    }

    public function submit(ChunkRequest $request): string
    {
        $this->calls[] = $request;
        $this->maybeFail();
        $id = 'job-' . count($this->calls);
        $this->jobs[$id] = ['request' => $request, 'polls' => 0];

        return $id;
    }

    public function poll(string $jobId): ?ChunkResult
    {
        $this->polls[] = $jobId;
        if (!isset($this->jobs[$jobId])) {
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'Unknown job.');
        }
        $this->jobs[$jobId]['polls']++;
        if ($this->jobs[$jobId]['polls'] <= $this->pollsBeforeDone) {
            return null;
        }

        return $this->hear($this->jobs[$jobId]['request']);
    }

    /**
     * The global script word at index $i (1-based).
     *
     * @return array{start: int, end: int, text: string, speaker: int}
     */
    public function scriptWord(int $i): array
    {
        $start = ($i - 1) * $this->wordEveryMs;

        return [
            'start' => $start,
            'end' => $start + $this->wordLengthMs,
            'text' => 'w' . $i,
            'speaker' => $this->diarise ? intdiv($i - 1, max(1, $this->turnWords)) % max(1, $this->speakers) : 0,
        ];
    }

    /**
     * Every script word wholly inside [0, durationMs).
     *
     * @return list<string>
     */
    public function scriptTexts(int $durationMs): array
    {
        $out = [];
        for ($i = 1;; $i++) {
            $w = $this->scriptWord($i);
            if ($w['end'] > $durationMs) {
                break;
            }
            $out[] = $w['text'];
        }

        return $out;
    }

    private function maybeFail(): void
    {
        if ($this->failures === []) {
            return;
        }
        $f = array_shift($this->failures);
        if ($f === null) {
            return;
        }
        if ($f instanceof \Throwable) {
            throw $f;
        }
        switch ($f) {
            case '429':
                throw new ProviderException(ProviderException::TRANSIENT, 'Rate limited.', true, 429, $this->retryAfter);
            case '500':
            case '503':
                throw new ProviderException(ProviderException::TRANSIENT, 'Server error.', true, (int) $f);
            case 'timeout':
                throw new ProviderException(ProviderException::TRANSIENT, 'Timed out.', true, null);
            case '413':
                throw new ProviderException(ProviderException::TOO_LARGE, 'File too large.', false, 413);
            case '401':
                throw new ProviderException(ProviderException::AUTH, 'Invalid key.', false, 401);
            case '402':
                throw new ProviderException(ProviderException::ACCOUNT, 'Insufficient credit.', false, 402);
            case 'refusal':
                throw new ProviderException(ProviderException::REFUSED, 'Content refused.', false, 400);
            case 'empty':
                $this->empty = true;
                return;
        }
    }

    private function hear(ChunkRequest $request): ChunkResult
    {
        $from = $request->startMs;
        $to = $request->startMs + $request->lengthMs;
        $billed = (int) ceil($request->lengthMs / 1000);
        if ($this->empty) {
            return new ChunkResult([], '', $this->language, $billed);
        }
        $first = max(1, (int) ceil($from / $this->wordEveryMs) + 1);
        $chunkWords = [];
        for ($i = $first;; $i++) {
            $w = $this->scriptWord($i);
            if ($w['start'] < $from) {
                continue;
            }
            if ($w['end'] > $to) {
                break;
            }
            $chunkWords[] = $w;
        }
        $label = function (int $speaker) use ($request): string {
            if (!$this->diarise) {
                return '';
            }
            $n = max(1, $this->speakers);
            $p = $this->permuteLabels ? ($speaker + $request->index) % $n : $speaker;

            return 'spk_' . $p;
        };
        $groups = [];
        foreach ($chunkWords as $w) {
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last][0]['speaker'] === $w['speaker'] && count($groups[$last]) < 10) {
                $groups[$last][] = $w;
            } else {
                $groups[] = [$w];
            }
        }
        $segments = [];
        foreach ($groups as $g) {
            $words = array_map(static fn (array $w): Word => new Word($w['start'] - $from, $w['end'] - $from, $w['text']), $g);
            $segments[] = new Segment(
                '',
                $g[0]['start'] - $from,
                $g[count($g) - 1]['end'] - $from,
                $label($g[0]['speaker']),
                implode(' ', array_column($g, 'text')),
                $this->timing === 'words' ? $words : [],
                0.9,
                $this->language,
                $request->index,
            );
        }
        $text = implode(' ', array_column($chunkWords, 'text'));
        if ($this->timing === 'none') {
            return new ChunkResult([], $text, $this->language, $billed, 200, false);
        }

        return new ChunkResult($segments, $text, $this->language, $billed);
    }
}
