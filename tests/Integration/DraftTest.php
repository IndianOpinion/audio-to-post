<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Integration;

use IndianOpinion\AudioToPost\Draft\LlmJsonCaller;
use IndianOpinion\AudioToPost\ProviderException;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\SourceException;
use IndianOpinion\AudioToPost\Tests\Support\AudioFixtures;
use IndianOpinion\AudioToPost\Tests\Support\WpTestCase;
use IndianOpinion\AudioToPost\WordPress\Settings;
use IndianOpinion\LlmJson\Testing\MockTransport;

final class DraftTest extends WpTestCase
{
    private MockTransport $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new MockTransport();
        $this->p->setLlmTransport($this->llm);
        $this->settings(['model_provider' => 'openai', 'model' => 'text-model']);
        Settings::saveSecret('model', 'sk-model-secret-abcdefghijklmnop');
    }

    /** @return array<string, mixed> */
    private static function reply(string $quoteSegment = 's0003', string $quote = 'w21 w22 w23'): array
    {
        return [
            'title' => 'A <em>talk</em>',
            'excerpt' => 'Short summary',
            'sections' => [['heading' => 'Start', 'paragraphs' => [['text' => 'They spoke.', 'segment_ids' => ['s0001']]]]],
            'quotes' => [['segment_id' => $quoteSegment, 'text' => $quote], ['segment_id' => 's0001', 'text' => 'never said']],
            'topics' => [['title' => 'Opening', 'segment_id' => 's0001']],
            'speaker_names' => [['speaker' => 'Speaker 2', 'name' => 'Guest', 'evidence_segment_id' => 's0002']],
        ];
    }

    private function transcribed(string $mode, float $seconds = 20.0): Run
    {
        $run = $this->confirmedRun(AudioFixtures::mp3($seconds)['path'], ['mode' => $mode]);
        $this->p->runner()->tick(60.0, 1);
        $this->p->runner()->tick(60.0);

        return $this->runOf($run->id);
    }

    /** T86, T63 and T69: draft calls go through llm-json-php, the reply is checked and the post is a draft. */
    public function testArticleThroughLibrary(): void
    {
        $this->llm->push(MockTransport::completion('{"title": "truncated'), MockTransport::completion(self::reply()));
        add_filter('audiopost_draft_post', static fn (array $p): array => ['post_status' => 'publish'] + $p);
        $run = $this->transcribed('article');
        self::assertSame(RunState::DRAFT_READY, $run->state, (string) $run->errorMessage);
        self::assertCount(2, $this->llm->requests(), 'malformed JSON retried by the library');
        $body = (string) $this->llm->requests()[0]->body;
        self::assertStringContainsString('segment_ids', $body, 'the request carries the schema');
        $post = get_post($run->draftIds[0]);
        self::assertSame('draft', $post?->post_status);
        self::assertSame(1, (int) $post?->post_author);
        self::assertSame('A talk', $post?->post_title, 'no HTML from the model is kept');
        self::assertStringContainsString('#t=8">00:08</a></cite>', (string) $post?->post_content);
        self::assertStringNotContainsString('never said', (string) $post?->post_content);
        self::assertSame($run->id, (int) get_post_meta($run->draftIds[0], '_audiopost_run_id', true));
        $usage = $this->p->repo()->usage($run->id);
        self::assertSame('draft', $usage[1]['kind']);
        self::assertSame(200, $usage[1]['tokens_in']);
    }

    /** T62: transcript mode makes no model call; a long transcript uses two passes. */
    public function testTranscriptModeAndTwoPass(): void
    {
        $run = $this->transcribed('transcript');
        self::assertSame(RunState::DRAFT_READY, $run->state);
        self::assertCount(0, $this->llm->requests());
        $this->settings(['single_pass_max_tokens' => 1000, 'block_words' => 100]);
        $notes = ['key_points' => ['point'], 'quote_segment_ids' => ['s0002'], 'topics' => [['title' => 'T', 'segment_id' => 's0001']]];
        for ($i = 0; $i < 10; $i++) {
            $this->llm->push(MockTransport::completion($notes));
        }
        $this->llm->push(MockTransport::completion(self::reply()));
        $long = $this->transcribed('article', 400.0);
        self::assertSame(RunState::DRAFT_READY, $long->state, (string) $long->errorMessage);
        $n = count($this->llm->requests());
        self::assertGreaterThan(2, $n);
        self::assertStringContainsString('Notes so far', (string) $this->llm->requests()[1]->body);
        self::assertStringContainsString('- point', (string) $this->llm->requests()[1]->body, 'notes are carried forward');
        self::assertStringContainsString('Candidate quote segments', (string) $this->llm->requests()[$n - 1]->body);
    }

    /** T64: a draft failure returns to transcript_ready with the transcript intact. */
    public function testDraftFailure(): void
    {
        $this->llm->push(MockTransport::status(401, '{"error":{"message":"bad key"}}'));
        $run = $this->transcribed('article');
        self::assertSame(RunState::TRANSCRIPT_READY, $run->state);
        self::assertNotNull($run->errorCode);
        self::assertNotNull($this->p->repo()->transcript($run->id));
    }

    /** T65, T68 and T70: another draft is a new post; renames reach future drafts only; deleting a run keeps drafts. */
    public function testSecondDraftRenameAndDelete(): void
    {
        $this->llm->push(MockTransport::completion(self::reply()));
        $run = $this->transcribed('article');
        $first = $run->draftIds[0];
        $firstContent = (string) get_post($first)?->post_content;
        $suggest = $this->p->repo()->meta($run->id, 'speaker_suggestions');
        self::assertSame('Guest', $suggest[0]['name']);
        self::assertSame('', $this->p->repo()->transcript($run->id)?->speaker('S2')?->name, 'a suggestion is never applied');
        $this->p->runs()->editTranscript($run->id, [['op' => 'rename_speaker', 'speaker' => 'S2', 'name' => 'Ravi']], 0);
        $this->p->runs()->createDraft($run->id, 'transcript');
        $this->drain();
        $run = $this->runOf($run->id);
        self::assertCount(2, $run->draftIds, implode("\n", $this->logLines($run->id)));
        self::assertSame($firstContent, (string) get_post($first)?->post_content);
        self::assertStringContainsString('Ravi', (string) get_post($run->draftIds[1])?->post_content);
        self::assertStringNotContainsString('Ravi', $firstContent);
        $this->p->runs()->delete($run->id);
        self::assertNotNull(get_post($first));
        self::assertSame('', get_post_meta($first, '_audiopost_run_id', true));
    }

    /** T87: a site caller is used for the whole run with the same checks and usage. */
    public function testSiteCaller(): void
    {
        $calls = [];
        add_filter('audiopost_llm_caller', static function ($c, array $ctx) use (&$calls) {
            return static function (array $request, array $context) use (&$calls): array {
                $calls[] = $context['pass'];

                return self::reply() + ['tokens_in' => 7, 'tokens_out' => 3];
            };
        }, 10, 2);
        $run = $this->transcribed('show_notes');
        self::assertSame(RunState::DRAFT_READY, $run->state);
        self::assertSame(['single'], $calls);
        self::assertCount(0, $this->llm->requests());
        $u = $this->p->repo()->usage($run->id);
        self::assertSame(7, $u[1]['tokens_in']);

        remove_all_filters('audiopost_llm_caller');
        add_filter('audiopost_llm_caller', static fn () => 'not callable', 10, 2);
        $this->llm->push(MockTransport::completion(self::reply()));
        $other = $this->transcribed('article');
        self::assertSame(RunState::DRAFT_READY, $other->state);
        self::assertContains('audiopost_llm_caller returned a non-callable value; ignored', $this->logLines($other->id));
    }

    /** T87: a site caller reply that breaks the schema is retried within the attempt limit. */
    public function testSiteCallerReplyIsValidated(): void
    {
        $n = 0;
        $caller = static function () use (&$n): array {
            $n++;

            return $n === 1 ? ['title' => 1] : self::reply();
        };
        add_filter('audiopost_llm_caller', static fn () => $caller, 10, 2);
        $run = $this->transcribed('article');
        self::assertSame(RunState::DRAFT_READY, $run->state);
        self::assertSame(2, $n);
    }

    /** T85: a site caller that throws fails the draft step cleanly once; the run is not left drafting. */
    public function testThrowingSiteCallerDoesNotLoop(): void
    {
        $n = 0;
        $caller = static function () use (&$n): array {
            $n++;
            throw new \RuntimeException('boom');
        };
        add_filter('audiopost_llm_caller', static fn () => $caller, 10, 2);
        $run = $this->transcribed('article');
        self::assertSame(RunState::TRANSCRIPT_READY, $run->state);
        self::assertSame(1, $n, 'not retried on every tick');
        $this->drain();
        self::assertSame(1, $n);
        self::assertSame(ProviderException::BAD_RESPONSE, $run->errorCode);
    }

    /** T88: without llm-json-php the plugin loads; model modes are unavailable; transcript mode works. */
    public function testWithoutLibrary(): void
    {
        LlmJsonCaller::$assumeAvailable = false;
        self::assertFalse($this->p->drafts()->callerAvailable());
        try {
            $this->p->runs()->create(['kind' => 'url', 'url' => 'https://ok.example/a.mp3'], ['mode' => 'article', 'rights_confirmed' => true], 1);
            self::fail('expected no_llm_caller');
        } catch (SourceException $e) {
            self::assertSame('no_llm_caller', $e->errorCode());
        }
        $run = $this->transcribed('transcript');
        self::assertSame(RunState::DRAFT_READY, $run->state);
        self::assertNotSame('', audiopost_export_transcript($run->id, 'vtt'));
        $data = \IndianOpinion\AudioToPost\WordPress\Rest::runData($run);
        self::assertSame('no_llm_caller', $data['modes_unavailable']['article']);
    }

    /** T83: keys never appear in logs, exports or stored errors. */
    public function testKeysNeverLeak(): void
    {
        $key = 'sk-model-secret-abcdefghijklmnop';
        add_filter('audiopost_llm_caller', static fn () => static function () use ($key): \WP_Error {
            return new \WP_Error('x', 'upstream said invalid key ' . $key);
        }, 10, 2);
        $this->settings(['max_attempts' => 1]);
        $run = $this->transcribed('article');
        $hay = json_encode([$this->p->repo()->logs($run->id), $run->errorMessage, audiopost_export_transcript($run->id, 'json'), $this->p->repo()->meta($run->id, 'usage')]);
        self::assertStringNotContainsString($key, (string) $hay);
        self::assertStringContainsString('[redacted]', (string) $hay);
    }
}
