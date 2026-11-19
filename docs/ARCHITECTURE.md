# audio-to-post: architecture

This file records how 0.1.0 is built from `docs/PRD.md` and every
decision taken on the way. Case numbers refer to section 4 of the PRD.

## Modules

Pure PHP, usable and tested without WordPress:

| path | role |
|---|---|
| `src/Audio/FormatDetector.php` | magic bytes, HTML and RSS detection, MP4 and WebM video checks (cases 12, 14) |
| `src/Audio/Mp3Reader.php` | MPEG Layer III frame walker: exact frame count, frame-aligned offsets, Xing, Info and VBRI (cases 18, 22) |
| `src/Audio/WavReader.php` | RIFF `fmt ` and `data` chunks, canonical 44-byte header |
| `src/Audio/Prober.php` | builds `AudioInfo` (cases 15 to 19) |
| `src/Mp3Chunker.php`, `src/WavChunker.php` | `Chunker` implementations: snap the plan to frames, write chunk files (case 22) |
| `src/Plan/ChunkPlanner.php` | chunk lengths, overlap, tail rule, native long audio, plan validation (cases 20 to 27, 38) |
| `src/Estimate/*` | `EstimateCalculator`, `TokenRatios`, `Money` (cases 28 to 31) |
| `src/Stitcher.php` | speaker mapping, global times, seam cut and text join (cases 44 to 49) |
| `src/QuoteChecker.php`, `src/Text/Normalizer.php` | case 66 |
| `src/Transcript/*` | `CueParser` (import, case 79), `Exporter` (case 78), `Editor` (case 77), `Turns` (case 50), `Timecode` |
| `src/Draft/*` | schema and validator, generic prompts, prompt transcript text and blocks, `DraftComposer` (blocks and review notes), `BlockBuilder`, callers |
| `src/Adapter/*` | `TranscriptionAdapter` interface and value objects; `HttpMultipartAdapter`, `TranscriptFileAdapter`, `FakeAdapter` |
| `src/Http/*` | `HttpClient` interface, `UrlGuard` (cases 8, 9), `Downloader` (cases 10, 13), `FeedParser` (cases 3, 4) |

WordPress layer (`src/WordPress/`):

| class | role |
|---|---|
| `Plugin` (in `src/`) | service container; tests swap the clock, HTTP client, DNS resolver and model transport |
| `RunRepository` | runs as a private post type, one meta key per item, JSON encoded; chunk results one row per chunk; large transcripts in files |
| `RunService` | create, prepare (fetch, probe, plan, estimate), confirm, cancel, retry, drafts, edits, export, feed episodes |
| `Runner` | the background tick: checks, chunk calls, retries, 413 split, provider hold, async jobs, stitching, housekeeping, retention |
| `DraftService` | draft queue, single and two-pass model calls, post creation |
| `SpendGuard`, `KillSwitch`, `UsageLedger` | case 33 checks in order, case 55, the usage table |
| `PrivateStorage` | private directory, file registry with due times, sweep, orphans (cases 80 to 83) |
| `Lock`, `Options` | add_option based run lock; fresh option reads |
| `SpendMeter`, `GenQueue` | soft integrations (cases 71, 72) |
| `Rest`, `Cli`, `Admin`, `Privacy`, `Hooks`, `Uninstaller` | the interfaces of section 5 and section 6.2 |

`src/functions.php` holds the public functions of section 5.2.

## Data flow

1. **Create.** `audiopost_create_run()` or REST validates the source, the
   rights tick and the mode, checks a URL with the `UrlGuard`, and stores a
   run in state `new`. Nothing is fetched in the request.
2. **Prepare (free).** On the next tick, `RunService::prepare()` copies the
   attachment or streams the URL into the private directory (HEAD first for
   the size check), probes it, checks the duration limit, looks for a
   duplicate by hash, plans chunks for the chosen adapter and stores the
   plan and the estimate. The run waits in `awaiting_confirmation`.
   Transcript sources are parsed here instead and, with no model draft,
   go straight to `queued`.
3. **Confirm.** The editor sends the estimate fingerprint. It is
   recomputed; a mismatch is `estimate_stale`. The estimate is checked
   against the whole daily cap (`exceeds_daily_cap`) and against what is
   left today (queued for local midnight).
4. **Transcribe.** For each chunk without a stored result: budget check,
   then kill switch, caps, `audiopost_can_spend`, spend-meter and the cancel
   flag, all with fresh reads; then the chunk file is cut and sent. The
   result is stored before usage is recorded and before the run moves on.
5. **Stitch.** When every chunk has a result, the `Stitcher` builds the
   transcript, which is stored twice: the original and the edited copy.
6. **Draft.** Queued draft modes run through a caller (site filter, else
   llm-json-php). Replies are checked against the schema, quotes against
   segments, and a `draft` post is created. Review notes go to run meta and
   post meta, never to the content.
7. **Retention.** Entering a final state stamps a due time on the run's
   audio files; the end of every tick and the daily event delete due files,
   log each one and fire `audiopost_recording_deleted`.

## Decisions on the open questions (rulings of 14 November)

1. **Chunkable formats.** MP3 and WAV are chunkable in 0.1.0. Ogg and M4A
   are roadmap items. Reason: both splitters are pure PHP on frame or
   sample boundaries and can be tested exactly; Ogg needs page and granule
   rewriting and M4A needs `moov` rewriting. A long Ogg, FLAC or M4A file
   fails with `cannot_split_format` unless the adapter takes long audio.
2. **Overlap.** A fixed 10 second overlap (setting, 0 to 30, and the
   `audiopost_overlap_seconds` filter). It does not grow on its own after a
   weak mapping. Reason: a growing overlap changes the bill after the
   editor confirmed it; weak mappings are flagged for the editor instead.
3. **Asynchronous adapters.** 0.1.0 ships the interface (`submit`, `poll`
   and the `async` capability, honoured by the runner with backoff and
   `async_timeout_hours`), the fake adapter (which supports async for
   tests), and one reference adapter for a synchronous endpoint of the
   common "audio transcriptions" shape (`http-multipart`). A
   submit-and-poll reference adapter is a roadmap item.
4. **Large transcripts.** A transcript whose JSON is over 64 KB is written
   to a private file under `uploads/audio-to-post/transcripts/` with a
   random name; post meta then holds only the file name. Smaller ones stay
   in post meta. Reason: keeps every meta row far below common
   `max_allowed_packet` values. Transcript files follow the run (deleted
   with it), not the audio retention rule.
5. **Model caller.** Settled by the PRD: llm-json-php, plus the
   `audiopost_llm_caller` filter for a site caller.
6. **Two-pass draft.** Blocks of about 1500 words (setting `block_words`),
   cut on segment boundaries. Each notes pass returns key points, candidate
   quote segment ids and topic starts; the last 12 key points so far are
   carried forward to the next block as a short bullet list. The final
   pass gets all notes plus the candidate quote segments in full.
7. **HTML episode pages.** Refused (`not_audio`). Only direct audio URLs
   and RSS enclosures are accepted.
8. **Usage ledger.** A small table, `{prefix}audiopost_usage`, one row per
   paid call, indexed by local day; cap checks are one SUM query, records
   one insert. This replaces the per-day options of PRD section 6.1 item 3.
   Rows older than 400 days are deleted by the daily event; uninstall drops
   the table.
9. **Tokens per word.** Defaults: 1.3 for Latin script, 2.5 for Indic and
   Arabic scripts, 1.0 per character for Chinese, Japanese and Korean
   (where the unit is a character and the speech rate is the
   `characters_per_minute` setting, default 250). All are estimates,
   overridable per language tag or per script family on the settings
   screen. The script comes from the language setting, or from the
   transcript text once there is one. Operators measure their own ratio
   from the "estimate against actual" table on each run screen. This
   replaces the PRD's 1.4 default.
10. **Synced playback.** Not in 0.1.0. The transcript screen shows times
    and links; the default retention deletes the audio as soon as the run
    is final.

## Other decisions taken by the builder

- **Feed fetching.** Feeds are fetched through the plugin's own
  `Downloader` (the WordPress HTTP API with the safe-request option and
  redirects followed by hand) and parsed with SimpleXML with network
  access off and document type declarations refused, instead of core's
  feed fetcher. Reason: cases 8 and 9 (checks on every hop and no https to
  http) must apply to feed URLs too, and core's fetcher follows redirects
  itself. The open Podcasting 2.0 JSON transcript shape is also read.
- **State machine.** Two edges beyond the PRD table: `probed` to `failed`
  and `probed` to `cancelled`, for a failure or cancel between the probe
  and the estimate (for example a re-estimate of an expired run whose copy
  is gone). `draft_ready` is an active state so a newly queued draft runs.
- **Retry before confirmation.** A run that failed before its estimate was
  confirmed (a fetch or probe failure) cannot be retried; it gives
  `not_confirmed` and the editor starts a new run. Reason: a retry must
  never reach a paid call without a confirmed estimate.
- **Retry with a changed cost.** `retry` recomputes the remaining
  estimate; when it differs from the one stored at the last retry the call
  fails with `estimate_stale` and the caller passes the new fingerprint.
- **Fetch retries** are scheduled, not slept: 2, 8 and 30 seconds plus
  jitter, as the run's `due_at`.
- **Chunk retries.** Waits under 5 seconds are slept in the tick when the
  budget allows; longer ones are stored per chunk as `retry_at`.
  `Retry-After` is honoured up to 15 minutes.
- **413 handling.** The refused chunk is halved with the normal overlap,
  the plan is re-snapped to frames and stored, and the adapter's byte limit
  is stored 20 per cent below the refused chunk's size (option
  `audiopost_adapter_limits`).
- **Provider hold.** A 401, 403 or account error fails the run and pauses
  every queued or transcribing run on the same adapter for 30 minutes
  (`provider_hold`), with one admin notice.
- **Seam cut.** With word times, the cut is the gap between words nearest
  the overlap midpoint, preferring gaps on which both chunks agree. With
  segment times only, the earlier chunk's nearest segment end and the
  later chunk's nearest segment start are used. Otherwise the two spanning
  segments are joined on their longest common run of normalised words
  (searching the last and first 400 words). Measured with the fake adapter
  on a 61-minute recording: 9150 of 9150 script words, none duplicated.
- **Speaker mapping** uses segment intervals, not words, so it works for
  providers without word times. Confidence is shared time over the label's
  speech time in the overlap.
- **Unknown duration.** The upper bound uses the lowest plausible bitrate:
  MP3 32 kbit/s, WAV 64 kbit/s, Ogg and WebM 16 kbit/s, M4A 24 kbit/s,
  FLAC 96 kbit/s.
- **Billing rounding `none`** rounds each request up to the next whole
  second, so estimates never come out low.
- **Token estimate for two passes.** Notes passes: transcript tokens plus
  per-block prompt overhead plus the carried notes, plus 10 per cent.
  Final pass: all notes outputs, a quote allowance of 10 per cent of the
  transcript tokens (at most 8000) and the prompt, plus 10 per cent.
- **spend-meter contract (version 1, checked against spend-meter
  0.1.0).** Present when `SPEND_METER_API` is defined and equals 1.
  `spend_meter_pre_spend` gets null and an intent with `purpose`
  (`audio-to-post.transcribe` or `audio-to-post.draft`), `caller`,
  `provider`, `model` (the adapter id when an adapter has no model name),
  `est_input_tokens`, `max_output_tokens`, `est_units` (minutes, audio
  only) and `meta.run_id`. A null result means no meter is listening and
  the plugin's own guards decide; a call goes ahead only when `allowed` is
  exactly true; any other non-array result is a deny. Every attempt that
  reached the provider is recorded once with one call array
  (`spend_meter_record`, status `ok`, `error_billed` or
  `error_unbilled`, with the reservation id). `spend_meter_release` gets
  the reservation id alone and is used only when no call was made
  (cancel, missing copy).
- **gen-queue contract (checked against gen-queue 0.1.0).** On
  `gen_queue_register_types` the plugin calls
  `gen_queue_register_type('audio-to-post.chunk', handler, args)`. Jobs
  go through `apply_filters('gen_queue_enqueue_job', null, $type,
  $payload, $args)` with the payload `{run_id, chunk}`, an idempotency key
  per chunk, `on_conflict` `requeue` and a `cost_estimate` when prices are
  set. Anything but a job id (null, `WP_Error`) falls back to the
  plugin's own runner. The handler takes gen-queue's `Job` and
  `JobContext`, runs the chunk under the run lock with every check,
  reports cost through `addCost()` (marked `metered` when spend-meter
  recorded it) and throws gen-queue's `RetryableException` when the chunk
  could not run yet. A run resumed from a pause or retried hands its
  missing chunks to gen-queue again. Drafts always run in the plugin's
  own runner.
- **Cap races.** Every paid call is checked under one short site-wide
  lock, and its estimate is held against the caps (option
  `audiopost_inflight`) until its usage row is written or the hold
  expires (the lock lifetime; the async timeout for submitted jobs). Two
  runs racing near a cap may both wait, but they cannot overshoot it. A
  usage row the table refuses is kept in an option, counted in the totals
  and written later.
- **Locks.** An expired run lock is taken over with one conditional
  `UPDATE` on the option row, so only one process wins it.
- **Remote fetches.** Every hop is checked by the guard after DNS
  resolution and then pinned to the checked addresses (cURL `RESOLVE`),
  so a second DNS answer cannot point the request somewhere else. A
  resumed download is appended only when `Content-Range` starts at the
  requested byte.
- **Hostile audio headers.** Header fields are bounds-checked before they
  are read; a non-syncsafe ID3 size is ignored; the MP3 frame search has
  a time budget even for the first frame; a WAV `fmt ` chunk with
  impossible values fails, and one whose byte rate or block size
  disagrees with itself is neither split nor trusted for its length. An
  MP3 whose frames cannot all be read in time is not split.
- **Private directory fails closed.** If the directory, its `index.php`
  or its deny-all `.htaccess` (also in `transcripts/`) cannot be written,
  nothing is stored there and the run fails with `storage_unwritable`.
- **Prompt injection.** Transcript text is one line per segment in the
  prompt, so it cannot forge segment ids, and every prompt says the
  transcript is material, not instructions. Replies are schema-checked,
  quotes are checked against their segments, all text is escaped, and the
  result is always a draft.
- **Draft titles and excerpts** are model text with tags stripped; all
  body text is escaped and square brackets are encoded so no shortcode
  runs.
- **REST nonces.** Cookie-authenticated requests carry the core REST nonce,
  which WordPress checks before any route; every route then checks the
  capability and run ownership. Admin forms use their own nonces.
- **Kill switch storage.** Option `audiopost_disabled` holds 1 or 0 and is
  read with the object cache entry dropped first.
- **Debug log** lines go through `error_log` only with `WP_DEBUG_LOG`, and
  every stored or logged message passes through a redactor that strips
  every configured key.
- **Attachment types.** An attachment source must be a Media Library
  attachment with an `audio/*` type (`video/*` is `video_not_supported`,
  anything else `not_audio`); a transcript file must be `.vtt`, `.srt` or
  `.json`. The upload form checks the type before the file is saved.
- **mbstring** is required; **intl** is suggested (without it NFC
  normalisation is skipped).

## Not in 0.1.0

- A deletion that fails is counted, logged and retried at the next sweep
  and shown on the status screen. The delete function can be injected, and
  the test suite covers the failure and the retry.
- Usage for a failed model call is recorded only when the caller returns
  usage; llm-json-php failures carry none through its exception.
