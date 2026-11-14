# audio-to-post: product requirements

Status: draft for review. Version target: 0.1.0.

One line: a WordPress plugin that turns an uploaded audio file or a podcast
episode URL into a transcript and then a draft post, keeping speaker turns
and timestamps, with chunking for long recordings and a cost estimate the
editor confirms before any paid call is made.

Written from scratch; not derived from any third-party plugin. This PRD was
written from the one-line description, public API documentation and general
WordPress knowledge. It describes behaviour only. The builder chooses the
internal design and records every decision in `docs/ARCHITECTURE.md`.

Conventions used below:

- The plugin slug is `audio-to-post`. The PHP namespace is
  `IndianOpinion\AudioToPost`. Global functions, options, hooks, post meta
  keys, REST routes and constants use the prefix `audiopost` (for example
  `audiopost_settings`, `AUDIOPOST_DISABLED`). All names here are invented
  for this project.
- "Editor" means a logged-in WordPress user with the capability to create
  runs (section 8). "Operator" means the person who configures the plugin
  and the provider credentials.
- "Run" means one attempt to turn one recording into one transcript and,
  optionally, one or more drafts.
- "Provider" means a speech-to-text service reached through a
  transcription adapter. "Model" means a text model reached for the draft
  step.
- Times inside a recording are integer milliseconds from the start of the
  recording. Wall-clock times are UTC in storage and the site timezone on
  screen.

---

## 1. Problem

A newsroom records interviews, panels, press conferences and podcast
episodes. Turning one hour of audio into a usable article takes an editor
several hours: listen, type, find the quotes, check who said what and when.
Speech-to-text services do the typing well, but using them by hand has four
recurring failures:

1. **Files are too long.** Most transcription APIs refuse a request above a
   size or duration limit. Editors split files by hand in desktop tools,
   lose the time offsets, and the quotes in the article no longer match
   the recording.
2. **Speaker turns are lost.** A transcript without "who said this" is
   useless for quoting. When a recording is split, speaker labels restart
   in every piece, so "Speaker 1" in part two is not the same person as
   "Speaker 1" in part one.
3. **Cost surprises.** Transcription is billed by the minute and drafting
   by the token. Nobody knows the bill until after the run, and a mistaken
   batch of long files can spend a month's budget in an afternoon.
4. **Unchecked text reaches readers.** A model asked to "write an article
   from this transcript" invents quotes, attributes lines to the wrong
   person and makes up timestamps. If that text is published without a
   human in the loop, it is a correction waiting to happen.

audio-to-post fixes these inside WordPress: it fetches or accepts the
audio, measures it, shows the editor the minutes and tokens the run will
use (and the price, when the operator has entered their own), and only
after the editor confirms does it split, transcribe, stitch the pieces back
with consistent speakers and true timestamps, and write a draft whose
quotes are checked against the transcript. Nothing is ever published by the
plugin.

Measured result: none yet. This is a new project, written from an idea.
The builder records one measured run (recording length, chunk count, wall
time, estimate against actual minutes and tokens) in the README once 0.1.0
works, with no prices.

## 2. Goals and non-goals

### Goals

1. Accept a recording from three sources: a Media Library audio
   attachment (including a fresh upload), a direct audio file URL, or a
   podcast RSS feed URL plus a chosen episode.
2. Measure the recording (format, duration, size) without any paid call,
   and show a pre-run estimate: audio minutes to be billed, chunk count,
   estimated draft tokens in and out, and a price only when the operator
   has entered prices for the configured provider and model.
3. Require an explicit editor confirmation of that estimate before the
   first paid call.
4. Split long recordings into chunks the provider accepts, with a small
   overlap, and stitch the results into one transcript with global
   timestamps, consistent speaker labels and no duplicated or missing
   words at the seams.
5. Keep speaker turns and timestamps through to the draft. Timestamps in
   the draft always come from the transcript, never from the model.
6. Reach any transcription API through a small adapter interface. Ship one
   generic HTTP adapter for the common multipart "audio transcriptions"
   request shape, one adapter that imports an existing transcript file
   (WebVTT, SRT, or the plugin's JSON), and one fake adapter for tests.
7. Produce drafts in three modes: article, show notes, and cleaned
   transcript. Every draft is a WordPress post with status `draft`.
8. Enforce a daily cap and a kill switch of its own, checked before every
   paid call, even when a shared queue or a spend meter is also present.
9. Integrate softly with `gen-queue` and `spend-meter` (both public
   IndianOpinion projects) when they are active, and work fully without
   them.
10. Export the transcript as JSON, WebVTT, SRT and plain text.

### Non-goals

1. Publishing anything. The plugin never sets a post status other than
   `draft`, never schedules a post and never sends anything to readers.
2. Shipping prices. The repository contains no price for any provider or
   model. The operator types their own.
3. Video. Video files and video URLs are refused in 0.1.0 (case 14).
4. YouTube or any other video site. A watch page is never fetched or
   parsed. A separate project covers YouTube through its official API.
5. Fetching or parsing arbitrary web pages. An HTML episode page is
   refused with a message asking for the audio file URL or the feed URL
   (case 12).
6. Decoding or re-encoding audio. No shell, no `ffmpeg`, no native
   extension. Chunking works on container and frame boundaries in pure
   PHP for the formats listed in case 22; other formats go to the provider
   whole or are refused.
7. Live or streaming transcription.
8. Voice identification of real people. Speaker labels are anonymous
   ("Speaker 1"); the editor names them.
9. Translation. The transcript is in the language spoken.
10. A vendor-specific integration. No adapter is named after, or shaped
    around, any product other than the generic request shape in goal 6.

## 3. Users and setting

- **Who installs it:** a site operator on a self-hosted WordPress site,
  usually a small newsroom or a podcast publisher.
- **Who uses it:** editors and authors who upload or link a recording and
  then edit the draft.
- **Where it runs:** inside WordPress (single site or multisite, per site).
  All paid work runs in the background: WP-Cron by default, WP-CLI or a
  system cron for long recordings, or `gen-queue` when it is active. No
  paid call ever runs inside a front-end page request, and none runs in an
  admin page request except the short confirmation request that enqueues
  the work.
- **Network:** the server makes outbound HTTPS calls to the provider the
  operator configured, to the model endpoint for drafting, and to the
  audio or feed URL the editor supplied.
- **Disk:** the plugin needs temporary disk space for downloaded audio and
  chunks, roughly twice the size of the largest recording, in a private
  directory under the uploads directory (section 6).

## 4. Behaviour

Cases are numbered once and referenced by number elsewhere. Section 12 maps
each case to at least one acceptance test.

### 4.1 Run lifecycle

A run moves through these states. Only the transitions listed are allowed;
any other attempted transition is refused with a typed exception and a log
line.

| state | meaning |
|---|---|
| `new` | created, source recorded, nothing fetched |
| `fetching` | downloading or copying the audio to private storage |
| `probed` | format, duration and size known, estimate computed |
| `awaiting_confirmation` | estimate shown, waiting for the editor |
| `queued` | confirmed, waiting for the runner |
| `transcribing` | chunks being sent; progress is `done/total` |
| `paused` | stopped by the cap or the kill switch; resumes on its own (cases 52, 56) |
| `transcript_ready` | stitched transcript stored |
| `drafting` | draft request in flight |
| `draft_ready` | at least one draft post created |
| `empty` | the recording held no speech (case 34) |
| `failed` | stopped by an error that needs a human (reason recorded) |
| `cancelled` | stopped by the editor |
| `expired` | estimate not confirmed in time (case 40) |

Allowed transitions: `new` to `fetching`, `failed`, `cancelled`;
`fetching` to `probed`, `failed`, `cancelled`; `probed` to
`awaiting_confirmation`, or straight to `queued` when the source is free
(case 39); `awaiting_confirmation` to `queued`, `cancelled`, `expired`;
`queued` to `transcribing`, `paused`, `cancelled`; `transcribing` to
`transcript_ready`, `empty`, `paused`, `failed`, `cancelled`; `paused` to
`queued`, `cancelled`; `transcript_ready` to `drafting`; `drafting` to
`draft_ready`, `transcript_ready` (draft failed, case 64), `paused`;
`draft_ready` to `drafting` (another draft, case 65); `failed` to `queued`
(retry, case 60); `expired` to `probed` (re-estimate).

### 4.2 Sources

1. **Attachment source.** The editor picks an audio attachment from the
   Media Library, or uploads one through the plugin screen (which uses the
   normal Media Library upload, so the file becomes an attachment). The run
   stores the attachment id. The plugin copies the file to private storage
   before chunking, so a later edit or deletion of the attachment does not
   corrupt a running job (case 57).
2. **Direct audio URL source.** The editor pastes an `http` or `https` URL
   that points at an audio file. The plugin downloads it to private storage
   in the background (state `fetching`), streaming to disk, never holding
   the whole file in memory.
3. **Podcast feed source.** The editor pastes an RSS 2.0 feed URL. The
   plugin fetches the feed (through WordPress core's feed fetching), lists
   the most recent episodes (title, publication date, duration when the
   feed gives it, enclosure size), and the editor picks one. The run stores
   the feed URL, the episode GUID, title and publication date, and the
   enclosure URL. The episode list is capped at 50 items, newest first;
   older episodes are reachable by pasting their enclosure URL directly.
4. **Feed transcript shortcut.** When the chosen episode carries a
   published transcript link in the open Podcasting 2.0 namespace
   (`podcast:transcript`) in a format the transcript-file adapter reads
   (WebVTT, SRT or JSON), the plugin offers "use the published transcript"
   as a free alternative. If the editor picks it, no audio is downloaded and
   no transcription call is made; the run goes straight to
   `transcript_ready` after parsing. If the link fails or the format is
   unreadable, the plugin says so and offers the audio route.
5. **Transcript file source.** The editor uploads a WebVTT, SRT or
   plugin-JSON transcript file they already have (for example from their
   own recording software). The run uses the transcript-file adapter, costs
   nothing, and can still produce drafts. An attachment or URL for the
   matching audio is optional and only used for the audio block in the
   draft.
6. **Rights confirmation.** Every new run from sources 1 to 3 requires the
   editor to tick "I have the right to transcribe and publish from this
   recording". The run stores the user id and time of the tick. Without it
   the run is not created (REST returns 400 with code `rights_not_confirmed`).
7. **Duplicate detection.** After fetching, the plugin computes a SHA-256 of
   the audio bytes. If a run with the same hash, the same adapter and the
   same transcription settings reached `transcript_ready` or later on this
   site within the retention window, the editor is offered "reuse that
   transcript" (free) or "transcribe again" (estimate shown as usual).
   Reuse is the default button. For URL sources a cheaper first check
   compares the normalised URL plus `Content-Length` and `ETag` or
   `Last-Modified`, but only the hash confirms a duplicate.

### 4.3 Fetching and validating the audio

8. **Scheme and host checks.** Only `http` and `https` URLs are accepted.
   Requests go through the WordPress HTTP API with the safe-request options
   (`reject_unsafe_urls`), so loopback, private, link-local and reserved
   addresses are refused, after DNS resolution, on the first request and on
   every redirect. A refused URL fails with code `unsafe_url`.
9. **Redirects.** At most 5 redirects are followed. Each hop is checked as
   in case 8. A redirect from `https` to `http` is refused.
10. **Size limit.** The maximum audio size is a setting (default 500 MB,
    filterable). If `Content-Length` is larger, the download does not start.
    If the header is missing or wrong, the download is aborted as soon as
    the written bytes pass the limit, the partial file is removed and the
    run fails with `too_large`.
11. **Duration limit.** The maximum duration is a setting (default 4 hours).
    A recording longer than that fails at the probe with `too_long`, before
    any paid call.
12. **Not audio.** The plugin checks both the `Content-Type` and the first
    bytes of the file (magic numbers for MP3 with or without an ID3 tag,
    WAV/RIFF, Ogg, FLAC, MP4/M4A `ftyp`, WebM/Matroska). The magic bytes win
    when they disagree with the header. An HTML or XML body is refused with
    `not_audio` and a message: "This looks like a web page. Paste the audio
    file link or the podcast feed link instead." A feed pasted as a direct
    audio URL is detected (XML with an `rss` root) and the editor is
    switched to the feed picker.
13. **Download failures.** A non-2xx response, a timeout, a TLS error or a
    connection reset is retried up to 3 times with exponential backoff
    (2 s, 8 s, 30 s, with jitter) inside the background job. A `401`,
    `403`, `404` or `410` is not retried; the run fails with
    `source_unavailable` and the HTTP status. Ranged resume of a partial
    download is used when the server advertises `Accept-Ranges: bytes`;
    otherwise the retry starts from zero.
14. **Video.** A video container (an MP4 whose track list holds a video
    track, a WebM with a video track, any `video/*` type) fails with
    `video_not_supported`. An M4A or MP4 with audio only is accepted. When
    the track list cannot be read, the file is treated as audio and the
    provider's answer decides.
15. **Empty file.** A zero-byte file, or a file whose measured duration is
    under 1 second, fails with `empty_audio`, without a paid call.
16. **Corrupt or unreadable file.** If the format is recognised but the
    duration cannot be measured (case 18 fails), the run moves to `probed`
    with `duration_known = false`. The estimate is shown as "unknown
    duration, upper bound from file size" (case 28). The editor may still
    confirm. If the provider later refuses the file, the run fails with
    `provider_rejected_audio` and the provider's message.

### 4.4 Probing

17. **What the probe records.** Container format, codec when known, sample
    rate, channels, bitrate and whether it is variable, duration in
    milliseconds, size in bytes, SHA-256, and whether the duration is exact
    or estimated.
18. **How duration is measured.** First WordPress core's audio metadata
    reader (the getID3 library that core bundles, through its public media
    metadata function). For MP3, when the file has no
    Xing/Info or VBRI header and the bitrate varies, that reader's figure is
    an estimate; the plugin then counts MPEG frames to get the exact length,
    capped at 10 seconds of CPU, falling back to the estimate with
    `duration_exact = false`. For WAV the duration comes from the data chunk
    size and the byte rate. For other formats the core reader's figure is
    used as given.
19. **Probe is free.** The probe makes no paid call and needs no provider
    configuration. It runs even when the kill switch is on, so an editor can
    see an estimate while spending is stopped.

### 4.5 Chunk planning

20. **Adapter limits.** Each adapter declares its limits (section 5.3):
    maximum bytes per request, maximum seconds per request, accepted
    formats, and whether it accepts long audio natively. The planner reads
    them; the operator can lower them in settings, never raise them above
    what the adapter declares.
21. **No chunking needed.** If the file is within both limits, the plan is
    one chunk covering the whole recording, with no overlap.
22. **Chunkable formats.** In 0.1.0 the plugin can split:
    - MP3 (MPEG-1/2/2.5 Layer III), on frame boundaries. Each chunk is a
      valid MP3: it starts at a frame sync, carries no ID3 tag except an
      optional minimal one, and drops any Xing/Info header that would
      describe the whole file.
    - WAV (PCM in RIFF), on sample-frame boundaries. Each chunk gets its own
      correct RIFF and `fmt ` header and data size.
    Other formats are not split in 0.1.0 (open question 1).
23. **Chunk length.** Target chunk length is the smaller of the adapter's
    maximum seconds and the bytes limit converted to seconds at the file's
    bitrate, less a 5 per cent safety margin, and never more than the
    setting `chunk_seconds` (default 600, minimum 60). Chunks are equal in
    length except the last, which is never shorter than 30 seconds: a short
    tail is merged into the previous chunk if that keeps the previous chunk
    within limits, otherwise the boundary is moved earlier to balance the
    last two.
24. **Overlap.** Consecutive chunks overlap by `overlap_seconds` (default 10,
    range 0 to 30). The overlap exists to rejoin words cut at the boundary
    and to map speaker labels across chunks (case 44). Overlap seconds are
    billed and are included in the estimate.
25. **Too big and not chunkable.** If the file exceeds the adapter's limits
    and its format is not chunkable, and the adapter does not accept long
    audio natively, the run fails at the probe with
    `cannot_split_format` and the message: "This file is too long for the
    configured provider and this format cannot be split. Convert it to MP3
    or WAV and try again." No paid call is made.
26. **Native long audio.** If the adapter declares that it accepts long
    audio natively (for example by an asynchronous job), the plan is one
    chunk and the adapter's submit and poll path is used (case 76).
27. **Plan is stored.** The chunk plan (index, start and end in ms within
    the recording, byte range in the source file, overlap before and after)
    is stored with the run before any paid call, so a retry resumes the same
    plan.

### 4.6 Estimate and confirmation

28. **Minutes estimate.** Billed audio seconds = the sum of chunk lengths
    including overlaps. When the duration is unknown (case 16), the upper
    bound is file size divided by the lowest plausible bitrate for the
    format (MP3 32 kbit/s, others as the builder documents), flagged as an
    upper bound. The adapter may declare a billing rounding rule (per
    request: round up to the next whole second, or the next minute, or a
    minimum billable length); the estimate applies it per chunk.
29. **Token estimate for drafting.** Estimated transcript words =
    speech minutes times `words_per_minute` (default 150). Estimated input
    tokens = words times `tokens_per_word` (default 1.4) plus the prompt
    overhead (the token length of the system and instruction text, counted
    as characters divided by 4) plus 10 per cent. For a long transcript that
    needs the two-pass draft (case 62), both passes are added. Estimated
    output tokens = the configured `max_output_tokens` for the mode (the
    worst case). The editor sees "up to N output tokens". The settings
    screen explains that scripts other than Latin often need a higher
    tokens-per-word figure, and the operator can set it per language
    (open question 9).
30. **Price.** Prices are never shipped. The operator may enter, per
    transcription adapter, a price per audio minute, and per draft model a
    price per million input tokens and per million output tokens, each as a
    decimal string with a currency label of their choice. When all needed
    prices are present, the estimate shows a total, a breakdown and the
    words "estimate, not a quote". When any is missing, the estimate shows
    minutes and tokens only, and the line "price not configured". The
    estimate never shows a price that mixes configured and missing parts.
31. **Asynchronous adapters.** An adapter that works by submit and poll is
    estimated the same way. Polling is free and is not counted.
32. **Confirmation.** The estimate screen shows: source, duration (and
    whether exact), chunks, billed minutes, draft mode, estimated tokens,
    price when configured, today's spend and remaining cap (case 51), and
    two buttons: "Start" and "Cancel". "Start" sends the run id and the
    estimate's fingerprint (a hash of the plan, the settings that affect
    cost and the price snapshot). The server recomputes the fingerprint; if
    it differs (settings or prices changed, or the file changed), it refuses
    with `estimate_stale` and shows the new estimate for a fresh
    confirmation.

### 4.7 Transcription

33. **One chunk per call.** Each chunk is sent in its own call. Before each
    call the plugin checks, in this order, with fresh reads that bypass the
    object cache: the kill switch (case 55), the daily cap (case 52),
    `spend-meter` if active (case 71), and the run's cancel flag. Any "no"
    stops before the call.
34. **Empty transcript.** If every chunk returns no text (silence, music
    only), the run moves to `empty`. No draft is attempted. The billed
    minutes are still recorded.
35. **Idempotent chunks.** A chunk that already has a stored result is never
    sent again, including after a retry or a crash. A chunk result is
    stored before the run advances. A crash between the provider's answer
    and the store loses that one chunk's result; it is sent again on resume,
    and the log says "chunk N re-sent after interrupted store". This is the
    only case in which a chunk is paid twice.
36. **Concurrency.** One run is processed by at most one worker at a time
    (a lock with a 15-minute expiry, renewed after each chunk). Chunks of one
    run are sent in order. Different runs may be processed in parallel up to
    `max_parallel_runs` (default 1).
37. **Retries.** HTTP 429, 500, 502, 503, 504, timeouts and connection
    errors are retried up to `max_attempts` (default 4) with exponential
    backoff (base 5 s, cap 5 min, full jitter), honouring `Retry-After` when
    present up to 15 min. The retry is scheduled, not slept in the request,
    except for waits under 5 s.
38. **Payload too large.** If the provider answers 413 or its documented
    "file too large" error for a chunk, the plugin splits that chunk in two
    (when the format is chunkable), lowers the stored effective limit for
    this adapter by 20 per cent for future plans, logs it, and continues.
    A chunk below 60 seconds that is still refused fails the run with
    `provider_rejected_audio`.
39. **Free sources.** The transcript-file adapter, the feed transcript
    shortcut and a reused duplicate transcript cost nothing. Their runs skip
    `awaiting_confirmation` when the editor chose no draft step; when a draft
    step is requested, the estimate shows only draft tokens and still needs
    confirmation.
40. **Estimate expiry.** An estimate not confirmed within 24 hours (filterable)
    moves the run to `expired` and deletes the private audio copy. The
    editor can re-estimate, which re-fetches URL sources.
41. **Authentication and account errors.** HTTP 401 or 403 from the
    provider, or a documented "insufficient credit" or "quota exhausted"
    error, does not retry. The run moves to `failed` with `provider_auth`
    or `provider_account`, and every other queued run for the same adapter
    is paused for 30 minutes (one admin notice, not one per run), because
    sending them would fail the same way.
42. **Provider refuses the content.** A documented content refusal fails
    the chunk with `provider_refused`. The run fails and keeps its other
    chunks; the editor may retry or cancel.
43. **Language.** The setting `language` is either `auto` or a BCP 47 tag.
    It is passed to the adapter when the adapter supports a language hint.
    Each chunk result may report a detected language; the transcript stores
    the per-segment language when known and the run stores the most common
    one. Mixed-language recordings are kept as spoken.

### 4.8 Stitching, speakers and timestamps

44. **Speaker mapping across chunks.** Providers that label speakers do so
    per request, so labels restart in each chunk. For each pair of
    consecutive chunks, the plugin looks at the overlap window. For every
    pair (label in chunk k, label in chunk k+1) it sums the milliseconds
    during which both chunks have speech from those labels at the same
    recording time. It then assigns chunk k+1's labels to chunk k's global
    speakers greedily by the largest shared time, one to one. A label with
    no shared time above 1 second gets a new global speaker. Each mapping
    stores its confidence (shared ms divided by the label's speech ms in
    the overlap). Mappings under 0.5 confidence are flagged for the editor
    (case 67). Global speakers are named "Speaker 1", "Speaker 2" and so on,
    in order of first speech.
45. **Timestamps are global.** Every segment and word time from chunk k is
    shifted by the chunk's start in the recording. Shifted times are
    clamped to the recording's duration. The stitched transcript is sorted
    by start time; ties keep chunk order.
46. **Seam deduplication.** Inside an overlap window, both chunks
    transcribed the same audio. The plugin chooses a cut point at the
    midpoint of the overlap, then moves it to the nearest gap between words
    (when word times exist) or between segments (when only segment times
    exist) within the overlap. Text before the cut comes from chunk k,
    after it from chunk k+1. When neither words nor segment boundaries fall
    inside the overlap (one long segment spans it), the plugin compares the
    normalised text (Unicode NFC, case-folded, punctuation removed) of the
    two segments, finds the longest common run of words, and joins on it.
    If no common run of 3 or more words exists, both texts are kept with a
    seam marker for the editor (case 67). No word is ever silently dropped.
47. **No timestamps from the provider.** If an adapter returns text only,
    the transcript keeps one segment per chunk with the chunk's start and
    end, and the run is flagged `timestamps = coarse`. The draft then shows
    chunk-level times only. The plugin never invents finer times.
48. **No speakers from the provider.** If an adapter does not diarise, every
    segment gets "Speaker 1" and the run is flagged `speakers = none`. The
    editor can split speakers by hand on the transcript screen (case 77).
49. **Segment shape.** Each stitched segment has: id (stable string,
    `s` plus a zero-padded index), start ms, end ms, global speaker id,
    text, optional words (each with start, end, text, optional
    confidence), optional confidence, optional language, source chunk
    index, and flags (`seam`, `low_confidence_speaker`).
50. **Turns.** A turn is a run of consecutive segments with the same
    speaker. Turns are derived, not stored separately. A gap of more than
    `turn_gap_seconds` (default 4) between segments of the same speaker
    starts a new paragraph inside the turn but not a new turn.

### 4.9 Daily cap, kill switch and pauses

51. **Cap display.** The estimate screen shows today's spend and the
    remaining daily cap in every unit the cap is set in.
52. **Daily cap.** The operator may set up to three caps: audio minutes per
    day, draft tokens per day, and money per day (money only works when
    prices are set; without prices the money cap is ignored and the
    settings screen says so). The day is the site's local day. Defaults:
    120 audio minutes and 200 000 draft tokens per day; the money cap is
    empty. Setting a cap to empty removes that cap; setting it to zero
    blocks all paid work.
53. **Cap at confirmation.** When the estimate exceeds what is left of any
    cap today, "Start" is replaced by "Queue for tomorrow" and the reason.
    The run then starts at the first runner tick after local midnight
    when the cap allows it. A run whose estimate exceeds the whole daily cap
    can never fit and fails at confirmation with `exceeds_daily_cap`, with a
    message to raise the cap or shorten the recording.
54. **Cap during a run.** Actual usage can exceed the estimate. Before each
    chunk and before the draft call, the plugin checks the remaining cap
    against that next call's own estimate. If it does not fit, the run moves
    to `paused` with reason `daily_cap`, keeps all finished chunks, and
    resumes at the first tick after local midnight. A call already in
    flight is never interrupted.
55. **Kill switch.** One switch for the whole plugin: an option toggled on
    the settings screen or by WP-CLI, and a constant `AUDIOPOST_DISABLED`
    that forces it on (the option cannot override the constant). When on,
    no transcription or model call starts. Probing, estimating, viewing,
    editing, exporting and free sources still work. A call already in flight
    completes and its result is kept.
56. **Resume after the switch.** Runs stopped by the switch move to `paused`
    with reason `kill_switch` and resume on the first tick after the switch
    is turned off, in their original order.
57. **Source deleted mid-run.** The private copy is the one that is
    chunked, so deleting the attachment or the remote file during a run
    has no effect on the run. If the private copy itself is missing when a
    chunk is due (disk cleaned by hand), the run fails with
    `source_copy_missing`; a retry re-fetches URL and attachment sources if
    they still exist.
58. **Plugin deactivated mid-run.** Scheduled events are cleared on
    deactivation. In-flight calls finish and are stored. On reactivation,
    runs in `queued`, `transcribing`, `paused` and `drafting` are picked up
    again; a run stuck in `transcribing` or `drafting` with an expired lock
    is resumed from its last stored chunk or step.

### 4.10 Failures, retry and cancel

59. **Partial failure.** When a chunk fails permanently, the run moves to
    `failed` with the chunk index and reason. Finished chunks are kept.
60. **Retry.** "Retry" (REST, screen or CLI) moves a `failed` run back to
    `queued`. Only chunks without a stored result are sent. If the retry
    needs a fresh estimate (settings or prices changed), the editor sees the
    remaining cost and confirms again.
61. **Cancel.** "Cancel" sets a cancel flag. No new call starts after it.
    A call in flight completes, its usage is recorded, its result is
    discarded. The run moves to `cancelled`, the private audio is deleted,
    the transcript so far is kept for the retention period and can be
    exported. A cancelled run cannot be resumed; the editor starts a new
    one, and duplicate detection (case 7) reuses nothing because no full
    transcript exists.

### 4.11 Drafting

62. **Draft modes.**
    - `article`: a news-style article written from the recording. Model
      call required.
    - `show_notes`: a summary paragraph, a list of topics with the time
      each starts, and key quotes. Model call required.
    - `transcript`: the cleaned transcript as the post body, with speaker
      headings or bold speaker names and timestamps. No model call, free.
    The default mode is a setting (default `article`). The editor picks the
    mode on the estimate screen, and may add another mode later (case 65).
    For model modes, a transcript longer than `single_pass_max_tokens`
    (default 60 000 estimated tokens) uses two passes: first a short notes
    object per block of about 20 minutes (key points, candidate quote
    segment ids, topic starts), then the final draft from the notes plus
    the selected quote segments in full (open question 6).
63. **What the model is asked for.** The prompt is generic and fully
    replaceable (section 7). It must ask the model to: write only from the
    transcript; attribute every quote to the speaker label in the
    transcript; refer to quotes and topic starts by segment id, never by
    time; never invent names, numbers or facts; and answer in JSON of this
    shape:

    ```json
    {
      "title": "string, at most 120 characters",
      "excerpt": "string, at most 300 characters",
      "sections": [
        {
          "heading": "string or empty",
          "paragraphs": [
            { "text": "string", "segment_ids": ["s0012", "s0013"] }
          ]
        }
      ],
      "quotes": [
        { "segment_id": "s0042", "text": "verbatim words from that segment" }
      ],
      "topics": [
        { "title": "string", "segment_id": "s0007" }
      ],
      "speaker_names": [
        { "speaker": "Speaker 2", "name": "string", "evidence_segment_id": "s0003" }
      ]
    }
    ```

    `topics` is required for `show_notes` and optional otherwise.
    `speaker_names` is optional and is only ever a suggestion (case 68).
    The call goes through `indianopinion/llm-json-php` with this schema,
    so malformed or truncated JSON is retried there.
64. **Draft step failure.** If the model call fails after its retries, the
    run returns to `transcript_ready` with the error shown. The transcript
    is not affected. The editor may retry the draft.
65. **More than one draft.** The editor may create another draft from the
    same transcript in another mode, or the same mode again. Each creates a
    new draft post. An existing draft post is never overwritten, because
    the editor may have changed it.
66. **Quote check.** Every quote is checked against its segment: the quote,
    normalised (NFC, case-folded, whitespace collapsed, straight and curly
    quotes unified, punctuation removed), must appear in the normalised
    text of the cited segment or of that segment joined with its
    immediate neighbours of the same speaker. A quote that fails is removed
    from the draft and listed in the run's review notes as "quote not found
    in transcript". A quote that cites an unknown segment id is removed the
    same way. Speaker attribution in the draft is taken from the segment,
    never from the model's text.
67. **Review notes.** Every draft carries a review notes list (shown in a
    meta box on the post edit screen and stored with the run): removed
    quotes, low-confidence speaker mappings, seam markers, coarse
    timestamps, missing speakers, and unnamed speakers. The notes are never
    part of the post content.
68. **Speaker names.** Speaker labels stay anonymous in the draft until the
    editor names them on the transcript screen. A name the model suggests is
    shown as a suggestion next to the speaker with its evidence segment,
    never applied on its own. Renaming a speaker updates the transcript and
    future drafts, not drafts already created.
69. **Draft content.** The draft post is created with status `draft`, the
    run's creator as author, the title and excerpt from the model (or, for
    `transcript` mode, the episode title or file name), and block content:
    an Audio block for the attachment or the episode audio URL when one is
    known; the sections as Heading and Paragraph blocks; quotes as Quote
    blocks with the speaker name or label and a timestamp `hh:mm:ss` (or
    `mm:ss` under one hour) computed from the segment start; for
    `show_notes`, a List block of topics with their timestamps; and,
    when the setting `append_transcript` is on (default off), a Details
    block holding the full transcript. When the audio is an attachment,
    timestamps link to the audio with a media fragment (`#t=` seconds).
    All text is escaped for block content. No HTML from the model is kept:
    model text is plain text.
70. **Post links.** The draft stores the run id in post meta; the run stores
    the draft post ids. Deleting a draft does not delete the run. Deleting a
    run (by retention or by hand) leaves drafts untouched and removes the
    link meta.

### 4.12 Integration with gen-queue and spend-meter

71. **spend-meter present.** When `defined('SPEND_METER_API')` and its value
    is 1, before every paid call the plugin applies `spend_meter_pre_spend`
    with an intent: `purpose` `audio-to-post.transcribe` or
    `audio-to-post.draft`, `caller` `audio-to-post`, `provider` (the adapter
    or model provider slug), `model`, `est_units` (minutes, for
    transcription) or `est_input_tokens` and `max_output_tokens` (for the
    draft). If `allowed` is not exactly true, the run pauses with reason
    `spend_meter` and the reason code in the log. If a reservation id comes
    back, exactly one `spend_meter_record` or `spend_meter_release`
    follows. After the call, `spend_meter_record` is fired with the actual
    usage. The plugin's own cap and switch still apply first. When the
    constant is absent or a different value, spend-meter is treated as
    absent.
72. **gen-queue present.** When `GEN_QUEUE_VERSION` is defined, the plugin
    registers job types `audio-to-post.chunk` and `audio-to-post.draft` on
    `gen_queue_register_types` and enqueues one job per chunk through the
    `gen_queue_enqueue_job` filter, with idempotency key
    `<run id>:<chunk index>` and the chunk's cost estimate. The plugin's own
    WP-Cron runner then stays idle for those runs. Usage reported to
    gen-queue's job context sets `metered` true when spend-meter already
    recorded it. If the filter returns `null` or a `WP_Error`, the plugin
    falls back to its own runner and logs once.
73. **Neither present.** The plugin's own runner, cap and switch are
    complete on their own.

### 4.13 Runner

74. **WP-Cron runner.** A recurring event every minute (a custom schedule)
    processes due runs within a time budget of 25 seconds (filterable).
    A chunk call is only started if the remaining budget exceeds the
    adapter's declared typical latency for the chunk length, or 20 seconds
    when undeclared; otherwise the tick ends and the next one continues.
    The HTTP timeout for a transcription call is a setting (default 300 s);
    the README warns that a loopback WP-Cron request may be killed earlier
    by the web server, and recommends WP-CLI from system cron for
    recordings over 30 minutes.
75. **WP-CLI runner.** `wp audio-to-post run` processes due work until the
    queue is empty or the time limit is reached (section 5.6), honouring the
    same checks.
76. **Asynchronous adapter jobs.** For a submit and poll adapter, the runner
    submits, stores the provider job id, and polls on later ticks with
    backoff (15 s, then doubling to 5 min). A job that has not finished
    after `async_timeout_hours` (default 6) fails with `provider_timeout`.
    Polls do not count against the cap; the billed minutes are recorded when
    the job completes.

### 4.14 Transcript editing and export

77. **Transcript screen.** Shows the transcript by turn, with timestamps,
    speaker names and flags. The editor can rename a speaker, merge two
    speakers into one, reassign one segment to another speaker, split a
    segment at a word boundary, and fix text. Edits are saved with a
    revision counter; the first stored version from the provider is kept
    unchanged as the `original` alongside the edited version.
78. **Export.** JSON (the plugin's schema, section 6.3), WebVTT (with
    `<v Speaker>` voice tags), SRT (speaker name prefixed to the cue text),
    and plain text (`[hh:mm:ss] Name: text` per turn). Exports use the
    edited version, or the original on request.
79. **Import.** The transcript-file adapter reads the same JSON schema,
    WebVTT with or without voice tags, and SRT. Malformed cues are skipped
    and counted in the review notes; a file with no readable cue fails with
    `transcript_unreadable`. Imported files are capped at 10 MB.

### 4.15 Retention and clean-up

80. **Audio copies.** The private audio copy and chunk files are deleted as
    soon as the run reaches `transcript_ready`, `empty`, `cancelled` or
    `expired`, unless `keep_recording_hours` (default 0) says to keep them.
    A `failed` run keeps its copy for 7 days for a retry, then deletes it.
81. **Runs and transcripts.** Runs and their transcripts are kept for
    `retention_days` (default 90; 0 means keep for ever). A daily event
    deletes older runs that are in a final state. Drafts are never deleted.
82. **Orphans.** The daily event also deletes files in the private directory
    that no run references and that are older than 24 hours.

## 5. Public interface

The names below are the public contract from 0.1.0. The builder picks
internal class names beyond these and records them in
`docs/ARCHITECTURE.md`.

### 5.1 PHP objects (namespace `IndianOpinion\AudioToPost`)

| name | kind | role |
|---|---|---|
| `Run` | read-only object | id, state, source, plan, estimate, flags, created by, created at, chunk progress, draft ids, last error code and message |
| `Source` | value object | kind (`attachment`, `url`, `feed_episode`, `transcript_file`), reference fields |
| `AudioInfo` | value object | case 17 fields |
| `ChunkPlan`, `Chunk` | value objects | case 27 fields |
| `Estimate` | value object | billed seconds, chunks, tokens in, tokens out, price breakdown or null, currency label, fingerprint, flags (`duration_exact`, `upper_bound`) |
| `Transcript`, `Segment`, `Word`, `Speaker` | value objects | case 49 and section 6.3 |
| `DraftResult` | value object | post id, mode, review notes |
| `Chunker` | interface | `supports(AudioInfo): bool`, `split(string $path, ChunkPlan): iterable` yielding chunk file paths |
| `Mp3Chunker`, `WavChunker` | classes | case 22 |
| `Stitcher` | class | cases 44 to 48 |
| `QuoteChecker` | class | case 66 |
| `Exception` and subclasses | exceptions | `SourceException`, `AudioException`, `PlanException`, `ProviderException` (with `retryable`, `code`, `http_status`), `CapException`, `DisabledException`, `StateException`, `TranscriptFormatException`, all extending `AudioToPostException` |

The pure parts (`Mp3Chunker`, `WavChunker`, `Stitcher`, `QuoteChecker`,
transcript parsers and exporters, the estimate calculator) must be usable
and testable without WordPress.

### 5.2 Functions

| function | returns | notes |
|---|---|---|
| `audiopost_create_run(array $source, array $options = [])` | `Run` or `WP_Error` | options: `mode`, `language`, `adapter`, `rights_confirmed` (bool, required for audio sources) |
| `audiopost_get_run(int $id)` | `?Run` | |
| `audiopost_confirm_run(int $id, string $fingerprint)` | `true` or `WP_Error` | case 32 |
| `audiopost_cancel_run(int $id)` | bool | case 61 |
| `audiopost_retry_run(int $id)` | `true` or `WP_Error` | case 60 |
| `audiopost_create_draft(int $id, string $mode)` | `int` job or `WP_Error` | queues a draft, case 65 |
| `audiopost_get_transcript(int $id, bool $original = false)` | `?Transcript` | |
| `audiopost_export_transcript(int $id, string $format)` | string | `json`, `vtt`, `srt`, `txt` |
| `audiopost_is_disabled()` | bool | fresh read, case 55 |
| `audiopost_usage_today()` | array | minutes, tokens in, tokens out, money or null |
| `audiopost_register_adapter(TranscriptionAdapter $adapter)` | void | call on `audiopost_register_adapters` |

### 5.3 Transcription adapter interface

`TranscriptionAdapter`:

- `id(): string`, lower-case slug, `^[a-z0-9][a-z0-9-]{1,39}$`.
- `label(): string`, shown on screens.
- `capabilities(): AdapterCapabilities` with: `maxBytes` (int), `maxSeconds`
  (int), `formats` (list of container names), `diarisation` (bool),
  `wordTimestamps` (bool), `segmentTimestamps` (bool), `languageHint` (bool),
  `async` (bool), `longAudio` (bool, case 26), `billingRounding`
  (`second`, `minute`, or none, plus a minimum billable seconds),
  `typicalSecondsPerAudioMinute` (float, for case 74), `free` (bool).
- `isConfigured(): bool` and `configurationProblems(): string[]` for the
  settings screen.
- `transcribe(ChunkRequest $request): ChunkResult` for synchronous
  adapters. `ChunkRequest` carries the chunk file path, its format, start
  ms, length ms, language hint and a request id. `ChunkResult` carries
  segments with times relative to the chunk start, speaker labels as the
  provider gave them, detected language, billed seconds when the provider
  reports them, and the HTTP status.
- `submit(ChunkRequest $request): string` and `poll(string $jobId):
  ?ChunkResult` (null while pending) for asynchronous adapters.
- Errors are thrown as `ProviderException` with `retryable` set correctly;
  an adapter never returns a partial result silently.

Bundled adapters:

1. **Generic multipart adapter** (`http-multipart`). Posts a
   `multipart/form-data` request with fields `file` and `model`, plus
   optional `language`, `response_format` and extra fields from settings,
   to a configured endpoint URL with a bearer key. It parses a JSON
   response holding `text` and, when present, `segments` (each with
   `start`, `end` in seconds, `text`, optional `speaker`) and `words`.
   Field names for the response are configurable by dotted paths for
   services that use the same request shape with different response keys.
   Limits are settings with conservative defaults (25 MB, 1 200 s) that the
   operator must check against their provider's documentation. It builds
   the multipart body itself and sends it through the WordPress HTTP API;
   no SDK.
2. **Transcript file adapter** (`transcript-file`). Case 79. Free.
3. **Fake adapter** (`fake`). Only registered when `WP_DEBUG` is true or in
   tests. Returns deterministic segments with two speakers, configurable
   failures (429, 413, 500, timeout, refusal), and per-chunk label
   permutations so stitching can be tested.

### 5.4 Actions

| action | arguments |
|---|---|
| `audiopost_register_adapters` | none |
| `audiopost_run_created` | `Run $run` |
| `audiopost_run_state_changed` | `Run $run, string $from, string $to` |
| `audiopost_estimate_ready` | `Run $run, Estimate $estimate` |
| `audiopost_chunk_done` | `Run $run, int $index, array $usage` |
| `audiopost_transcript_ready` | `Run $run, Transcript $transcript` |
| `audiopost_draft_created` | `Run $run, int $post_id, string $mode, array $review_notes` |
| `audiopost_run_failed` | `Run $run, string $code, string $message` |
| `audiopost_usage_recorded` | `array $record` (section 6.4) |
| `audiopost_cap_reached` | `string $unit, string $used, string $cap, string $day` |
| `audiopost_kill_switch_changed` | `bool $old, bool $new, string $actor` |

### 5.5 Filters

| filter | default | purpose |
|---|---|---|
| `audiopost_prompt` | bundled generic prompt | `string $prompt, string $mode, string $pass, Run $run` |
| `audiopost_draft_schema` | case 63 schema | change the JSON schema, must stay a superset |
| `audiopost_draft_post` | built array | `array $postarr, Run $run, string $mode`; status is forced back to `draft` after the filter |
| `audiopost_draft_blocks` | built block list | change the block content |
| `audiopost_max_bytes` | setting | case 10 |
| `audiopost_max_seconds` | setting | case 11 |
| `audiopost_chunk_plan` | computed plan | may only shorten chunks or add overlap; a plan that breaks adapter limits is rejected |
| `audiopost_can_spend` | true | `bool $allow, array $intent`; may deny only |
| `audiopost_capability_create` | `upload_files` | plus `edit_posts`, see section 8 |
| `audiopost_capability_manage` | `manage_options` | settings and kill switch |
| `audiopost_tick_seconds` | 25 | case 74 |
| `audiopost_estimate_ttl` | 86400 | case 40 |
| `audiopost_retention_days` | setting | case 81 |
| `audiopost_allowed_hosts` | empty (all public hosts) | optional allow-list for URL sources |

Every filter result is validated. An invalid type is ignored with a log
line, and the default is used.

### 5.6 WP-CLI

| command | effect |
|---|---|
| `wp audio-to-post add <path-or-url> [--attachment=<id>] [--feed=<url> --episode=<guid>] [--mode=<m>] [--rights-confirmed] [--yes]` | create a run, fetch, probe and print the estimate; `--yes` confirms it (for scripted use) |
| `wp audio-to-post estimate <run>` | print the current estimate |
| `wp audio-to-post confirm <run>` | confirm |
| `wp audio-to-post run [--time=<s>] [--max-chunks=<n>] [--run=<id>]` | process due work |
| `wp audio-to-post status [--format=table\|json]` | switch, caps, usage today, counts by state |
| `wp audio-to-post list [--state=<s>] [--limit=50]` | runs |
| `wp audio-to-post show <run>` | one run with chunks and review notes |
| `wp audio-to-post cancel <run>` / `retry <run>` | cases 61, 60 |
| `wp audio-to-post draft <run> --mode=<m>` | case 65 |
| `wp audio-to-post export <run> --format=<f> [--original]` | to standard output |
| `wp audio-to-post disable` / `enable` | kill switch |
| `wp audio-to-post purge [--dry-run]` | retention clean-up now |

Exit codes: 0 success; 1 error; 3 stopped by the kill switch; 4 stopped by
a cap. `add` without `--rights-confirmed` fails for audio sources.

### 5.7 REST API

Namespace `audio-to-post/v1`. Every route checks the capability and a REST
nonce.

| method and route | effect |
|---|---|
| `POST /runs` | create (body: source, options) |
| `GET /runs` | list own runs (all runs for managers) |
| `GET /runs/{id}` | run with estimate and progress |
| `POST /runs/{id}/confirm` | body: fingerprint |
| `POST /runs/{id}/cancel`, `POST /runs/{id}/retry` | |
| `POST /runs/{id}/drafts` | body: mode |
| `GET /runs/{id}/transcript?format=json\|vtt\|srt\|txt&original=0\|1` | |
| `PATCH /runs/{id}/transcript` | edits, with the revision counter for optimistic locking (409 on mismatch) |
| `GET /feed-episodes?url=` | case 3 picker |

### 5.8 Constants

| constant | effect |
|---|---|
| `AUDIOPOST_DISABLED` | true forces the kill switch on |
| `AUDIOPOST_ADAPTER_KEY_<ID>` | API key for adapter `<ID>` in upper case with dashes as underscores; preferred over the option |
| `AUDIOPOST_MODEL_KEY` | API key for the draft model |
| `AUDIOPOST_DAILY_MINUTES`, `AUDIOPOST_DAILY_TOKENS`, `AUDIOPOST_DAILY_MONEY` | override the option caps; may only lower or equal them, never raise |
| `AUDIOPOST_DISABLE_CRON_RUNNER` | WP-Cron does housekeeping only; work runs from WP-CLI |

## 6. Data

### 6.1 Storage

1. **Runs** are a private custom post type `audiopost_run` (not public, not
   queryable, not in REST core routes, no front-end URL, shown in admin
   under the plugin's menu). The post author is the run creator. The post
   title is the source label. State, source, audio info, plan, estimate,
   chunk results, transcript, edits, usage and review notes are stored as
   post meta, one key per item, JSON-encoded with `JSON_UNESCAPED_UNICODE`.
   Chunk results are stored one meta row per chunk so a resume never
   rewrites the whole set (open question 4 covers very long transcripts).
2. **Settings** are one option `audiopost_settings` (autoloaded, no
   secrets) and one option `audiopost_secrets` (not autoloaded) for keys
   entered on screen. Kill switch: option `audiopost_disabled`, not
   autoloaded, read fresh.
3. **Usage ledger** is one option per local day, `audiopost_usage_<Y-m-d>`,
   not autoloaded, holding running totals, updated with an atomic
   compare-and-swap loop; days older than 400 are deleted by the daily
   event. A table is not needed for these volumes (open question 8).
4. **Private files** live in `wp-content/uploads/audio-to-post/` with a
   random 32-character hex name per file, an `index.php` and an
   `.htaccess` that denies all. The README tells nginx users to add a deny
   rule, and the settings screen warns when a test file in that directory
   is reachable over HTTP. Files are never attachments.
5. **Draft link meta** on posts: `_audiopost_run_id` and
   `_audiopost_mode` (underscore prefix: hidden from custom fields).

### 6.2 Retention and uninstall

Retention is section 4.15. Deactivation clears scheduled events only.
`uninstall.php` deletes: all `audiopost_run` posts and their meta, the
options above, the per-day usage options, the private directory and its
contents, and the `_audiopost_*` meta on drafts. It does not delete draft
posts or attachments, because the editor created or kept them. On
multisite it does this for every site.

### 6.3 Transcript JSON schema

```json
{
  "schema": "audio-to-post/transcript@1",
  "duration_ms": 3600000,
  "language": "en",
  "flags": { "timestamps": "fine", "speakers": "diarised" },
  "speakers": [ { "id": "S1", "label": "Speaker 1", "name": "" } ],
  "segments": [
    {
      "id": "s0001",
      "start_ms": 0,
      "end_ms": 4200,
      "speaker": "S1",
      "text": "Good morning and welcome.",
      "language": "en",
      "confidence": 0.93,
      "chunk": 0,
      "flags": [],
      "words": [ { "start_ms": 0, "end_ms": 380, "text": "Good" } ]
    }
  ]
}
```

`timestamps` is `fine` or `coarse`; `speakers` is `diarised`, `none` or
`edited`. Unknown keys are kept on import and ignored.

### 6.4 Usage record

Each paid call produces one record: run id, call kind (`transcribe` or
`draft`), adapter or model id, chunk index or pass, billed seconds, input
tokens, output tokens, money as a decimal string or null, currency label,
local day, UTC time, HTTP status, attempt number. Records are stored with
the run; the day ledger holds totals. Usage is recorded for failed calls
when the provider reports it as billed.

## 7. Configuration and sensible defaults

| setting | default | notes |
|---|---|---|
| Transcription adapter | none | must be chosen and configured before a paid run |
| Adapter endpoint, model, key, limits, response paths | empty, 25 MB, 1 200 s | generic adapter, section 5.3 |
| Draft model provider and model | empty | via llm-json-php provider settings |
| Price per audio minute, per adapter | empty | operator's own figure; no prices shipped |
| Price per million input and output tokens, per model | empty | same |
| Currency label | empty | free text, display only |
| Default draft mode | `article` | |
| Append transcript to draft | off | |
| Language | `auto` | |
| Chunk seconds | 600 | 60 to adapter max |
| Overlap seconds | 10 | 0 to 30 |
| Max audio size | 500 MB | |
| Max duration | 4 h | |
| Words per minute | 150 | estimate only |
| Tokens per word | 1.4 | estimate only, per language overrides |
| Max output tokens | 2 000 (`article`), 1 500 (`show_notes`) | |
| Single-pass limit | 60 000 tokens | case 62 |
| Daily cap: audio minutes | 120 | empty removes, zero blocks |
| Daily cap: draft tokens | 200 000 | |
| Daily cap: money | empty | needs prices |
| Kill switch | off | constant forces on |
| Max attempts per call | 4 | |
| Transcription HTTP timeout | 300 s | |
| Async timeout | 6 h | |
| Max parallel runs | 1 | |
| Estimate expiry | 24 h | |
| Keep audio after transcript | 0 h | |
| Retention | 90 days | 0 keeps for ever |

The prompt for each mode and pass is shown on the settings screen as a
plain textarea with a "restore default" button. The bundled prompts are
generic: they say what the draft must contain and the rules in case 63,
and nothing about any publication's style. Variables available in a
prompt: `{title}`, `{source_label}`, `{duration}`, `{language}`,
`{speakers}`, `{transcript}`, `{notes}`, `{mode}`; unknown variables are
left as typed and logged once.

## 8. Security and privacy

1. **Capabilities.** Creating, viewing and confirming own runs needs
   `upload_files` and `edit_posts`. Viewing or acting on other users' runs,
   settings, prices, caps and the kill switch need `manage_options`.
   Both are filterable. Draft posts are created through the normal post
   API as the run's creator, so a user who cannot publish still cannot
   publish.
2. **Nonces.** Every admin form and REST route checks a nonce and the
   capability. The confirm route also checks the fingerprint (case 32).
3. **Outbound requests.** Case 8 and 9 rules for every editor-supplied URL,
   including feed URLs, enclosure URLs and feed transcript links. Optional
   host allow-list filter.
4. **Secrets.** Keys are read from constants first. Keys typed on screen
   are stored in the non-autoloaded option, never shown again (the field
   shows the last 4 characters), never logged, never in exports, and
   stripped from any error message or response excerpt the plugin stores.
5. **Private audio.** Audio copies are not attachments and are not
   reachable by URL (section 6.1 item 4). They are deleted on the schedule
   in 4.15.
6. **Personal data.** Recordings and transcripts may hold personal data.
   The plugin sends audio only to the provider the operator configured and
   transcript text only to the model the operator configured. The plugin
   adds a suggested paragraph to the WordPress privacy policy guide. It
   registers personal data exporter and eraser callbacks for runs created
   by a user (run metadata and transcripts they created); erasure deletes
   those runs but not published or draft posts.
7. **Model output.** Model text is treated as untrusted plain text: escaped
   on output, no HTML kept, no shortcodes run, links not made clickable.
   Quote and speaker checks are in cases 66 and 68.
8. **File handling.** Uploaded transcript files are parsed as text only,
   never included or executed, and size-capped. Audio files are never
   executed and never passed to a shell.
9. **No `eval`, no shell, no `@`.** Errors are typed exceptions or
   `WP_Error` at the WordPress boundary.

## 9. Performance budget

1. Front end: zero queries and zero hooks with work on public pages.
2. Admin: the plugin's screens load their assets only on their own pages.
   Run list screen: one query for the page of runs, meta primed.
3. Probe: MP3 frame count capped at 10 s of CPU (case 18); memory under
   32 MB for any file size (streamed reads in 1 MB blocks).
4. Chunking: streamed copy of byte ranges; memory under 32 MB plus the
   size of one chunk when the adapter needs the chunk in memory for the
   request body.
5. Tick: within 25 s of wall time except for one in-flight call (case 74).
6. Stitching a 4-hour transcript: under 2 s and 64 MB.
7. Usage ledger update: one read and one write per call.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. `declare(strict_types=1)` in every library file.
- WordPress 6.4 or later, single site and multisite (per site).
- Runtime dependency: `indianopinion/llm-json-php` for the draft model call
  (bundled in the release zip; Composer `vcs` repository until it is on
  Packagist). No other runtime dependency; no SDKs.
- Works with the block editor; with the classic editor the draft is still
  valid post content (blocks degrade to HTML).
- Object caches: the kill switch, caps and locks use fresh reads (direct
  option reads with the cache bypassed, or `wp_cache_delete` before
  reading) so a stale cache can never allow a paid call.
- Page caches: no front-end output, so none affected.
- Hosts without loopback WP-Cron: WP-CLI runner and
  `AUDIOPOST_DISABLE_CRON_RUNNER`.
- PHP `max_execution_time` and web server timeouts: case 74.
- `allow_url_fopen` is not needed; all HTTP goes through the WordPress HTTP
  API.

## 11. Observability

1. **Run log.** Each run keeps an ordered list of log lines (state change,
   chunk sent, chunk done with billed seconds, retry with reason, pause
   with reason, seam markers, quote removals), capped at 500 lines, shown
   on the run screen.
2. **Status screen.** Kill switch, caps and today's usage, runs by state,
   adapter configuration problems, the private directory check, last tick
   time and how many ticks ran in the past hour (so a dead WP-Cron is
   visible).
3. **Admin notices.** One notice (not one per run) for: kill switch on,
   cap reached today, provider authentication or account problem (case 41),
   WP-Cron not ticking for 10 minutes while work is queued.
4. **Estimate against actual.** After each run, the run screen shows the
   estimate and the actual minutes and tokens side by side, so the operator
   can tune words per minute and tokens per word.
5. **Logging.** Through `error_log` only when `WP_DEBUG_LOG` is on, one line
   per state change, prefixed `[audio-to-post]`. Never a key, never
   transcript text.
6. **Hooks.** Every event in section 5.4 fires so another plugin can send
   alerts.

## 12. Acceptance tests

Each test names the case it proves. Tests use the fake adapter, a mock
transport for llm-json-php, generated MP3 and WAV fixtures (a few seconds
of silence and tone, built in the test, no copyrighted audio), and the
WordPress stub layer.

| test | case | expectation |
|---|---|---|
| T1 | 1 | attachment source copies the file to private storage; run stores attachment id |
| T2 | 2 | URL source streams to disk; peak memory stays under budget for a 100 MB fixture |
| T3 | 3 | feed with 80 items lists newest 50 with title, date, duration, size |
| T4 | 4 | episode with a WebVTT `podcast:transcript` offers the free route; picking it reaches `transcript_ready` with no adapter call |
| T5 | 5 | SRT upload creates a free run and a transcript |
| T6 | 6 | audio run without rights confirmation is refused with `rights_not_confirmed` |
| T7 | 7 | same bytes, same settings, second run offers reuse; reuse makes no call |
| T8 | 8 | URLs resolving to 127.0.0.1, 10.0.0.1, 169.254.169.254 and `[::1]` are refused with `unsafe_url` |
| T9 | 9 | sixth redirect refused; https to http redirect refused |
| T10 | 10 | `Content-Length` over the limit never downloads; missing header aborts at the limit and removes the partial file |
| T11 | 11 | 5-hour fixture header fails `too_long` before any call |
| T12 | 12 | HTML body fails `not_audio` with the message; RSS body switches to the picker |
| T13 | 13 | 503 then 200 succeeds on retry; 404 fails `source_unavailable` without retry |
| T14 | 14 | MP4 with a video track fails `video_not_supported`; audio-only M4A accepted |
| T15 | 15 | zero-byte file fails `empty_audio` |
| T16 | 16 | unmeasurable duration shows an upper-bound estimate |
| T17 | 17 | probe records every listed field |
| T18 | 18 | VBR MP3 without Xing header: frame count gives exact duration within 30 ms |
| T19 | 19 | probe and estimate work with the kill switch on |
| T20 | 20 | operator limit above adapter limit is clamped |
| T21 | 21 | short file plans one chunk with no overlap |
| T22 | 22 | each MP3 chunk starts on a frame sync and decodes as valid frames; each WAV chunk has a correct header and data size |
| T23 | 23 | 61-minute file with 10-minute chunks never ends with a tail under 30 s |
| T24 | 24 | overlap seconds appear in billed seconds |
| T25 | 25 | oversize FLAC with a sync adapter fails `cannot_split_format` with no call |
| T26 | 26 | long-audio adapter gets one chunk |
| T27 | 27 | plan stored before the first call; retry reuses it |
| T28 | 28 | billed seconds include per-chunk rounding to the minute when declared |
| T29 | 29 | token estimate follows the formula; two-pass adds both passes |
| T30 | 30 | no prices shows minutes and tokens with "price not configured"; partial prices show no total; full prices show total and "estimate, not a quote" |
| T31 | 31 | async adapter polls are not counted |
| T32 | 32 | changing a price after the estimate makes confirm fail `estimate_stale` |
| T33 | 33 | each check (switch, cap, meter, cancel) stops before the call, in order |
| T34 | 34 | all-empty chunks end in `empty`, minutes recorded, no draft |
| T35 | 35 | crash after store: no re-send; crash before store: one re-send with the log line |
| T36 | 36 | two workers on one run: only one sends |
| T37 | 37 | 429 with `Retry-After: 30` schedules the retry at 30 s |
| T38 | 38 | 413 splits the chunk and lowers the stored limit; under 60 s still refused fails the run |
| T39 | 39 | transcript-file run with no draft skips confirmation |
| T40 | 40 | unconfirmed after the TTL moves to `expired` and deletes the copy |
| T41 | 41 | 401 fails the run and pauses other runs on the adapter for 30 min with one notice |
| T42 | 42 | refusal fails the run and keeps other chunks |
| T43 | 43 | language hint is passed when supported; per-segment language stored |
| T44 | 44 | fake adapter permutes labels per chunk; stitched speakers are consistent; a mapping under 0.5 is flagged |
| T45 | 45 | segment times from chunk 3 are shifted by its start |
| T46 | 46 | seam with word times: no duplicate or missing words; seam inside one long segment joins on a common run; no common run keeps both with a marker |
| T47 | 47 | text-only adapter gives chunk-level segments and `coarse` flag |
| T48 | 48 | non-diarising adapter gives one speaker and the `none` flag |
| T49 | 49 | stitched segments carry every field in case 49 |
| T50 | 50 | gap over 4 s starts a paragraph, not a turn |
| T51 | 51 | estimate screen shows usage and remaining cap |
| T52 | 52 | zero cap blocks; empty cap removes; money cap ignored without prices |
| T53 | 53 | estimate over remaining cap queues for tomorrow; estimate over the whole cap fails `exceeds_daily_cap` |
| T54 | 54 | cap reached mid-run pauses at the chunk boundary and resumes after local midnight |
| T55 | 55 | constant on cannot be overridden by the option; no call starts |
| T56 | 56 | runs paused by the switch resume in order when it is turned off |
| T57 | 57 | deleting the attachment mid-run does not affect it; deleting the private copy fails `source_copy_missing` |
| T58 | 58 | deactivate then reactivate resumes from the last stored chunk |
| T59 | 59 | permanent chunk failure keeps finished chunks |
| T60 | 60 | retry of a failed run sends only missing chunks |
| T61 | 61 | cancel during a call: result discarded, usage recorded, copy deleted |
| T62 | 62 | `transcript` mode makes no model call; long transcript uses two passes |
| T63 | 63 | model request carries the schema; malformed JSON retried by the library |
| T64 | 64 | draft failure returns to `transcript_ready` with the transcript intact |
| T65 | 65 | second draft creates a new post; the first is unchanged |
| T66 | 66 | invented quote and unknown segment id removed and listed; curly quotes and Devanagari text match after normalisation |
| T67 | 67 | review notes list every flag type and never appear in post content |
| T68 | 68 | model speaker name shown as suggestion only; rename updates future drafts only |
| T69 | 69 | draft status is `draft` even when a filter sets `publish`; timestamps come from segments; model HTML is escaped |
| T70 | 70 | deleting a run leaves the draft and removes link meta |
| T71 | 71 | spend-meter deny pauses with `spend_meter`; reservation always followed by record or release; contract value 2 treated as absent |
| T72 | 72 | gen-queue present: one job per chunk with the idempotency key; `null` from the filter falls back |
| T73 | 73 | neither present: own runner completes a run |
| T74 | 74 | tick stops starting calls when the budget is short |
| T75 | 75 | WP-CLI `run` exits 3 when disabled and 4 when capped |
| T76 | 76 | async job past the timeout fails `provider_timeout` |
| T77 | 77 | speaker merge, segment split and text fix saved; original unchanged; stale revision gets 409 |
| T78 | 78 | exports match golden files for JSON, WebVTT voice tags, SRT and text |
| T79 | 79 | malformed cues skipped and counted; file with none fails `transcript_unreadable`; 11 MB file refused |
| T80 | 80 | audio deleted at `transcript_ready`; failed run keeps it 7 days |
| T81 | 81 | runs older than retention deleted, drafts kept |
| T82 | 82 | unreferenced private files older than 24 h deleted |
| T83 | 8.4 | keys never appear in logs, exports or stored error excerpts |
| T84 | 6.2 | uninstall removes runs, options, usage, private files and link meta, and keeps drafts and attachments |

## 13. Open questions

To be answered by the builder in `docs/ARCHITECTURE.md`.

1. Which formats beyond MP3 and WAV should be chunkable in 0.1.0? Ogg
   (page boundaries) is feasible in pure PHP; M4A/MP4 needs `moov` atom
   rewriting and may be too much for 0.1.0.
2. Is a 10-second overlap enough for reliable speaker mapping (case 44) in
   fast panel discussions, or should the overlap grow automatically when a
   mapping comes back under 0.5 confidence?
3. Should 0.1.0 ship a working asynchronous (submit and poll) reference
   adapter, or only the interface plus the fake adapter's async mode?
4. Post meta per chunk against a file per transcript: at what transcript
   size does post meta become a problem on common hosts (`max_allowed_packet`),
   and should large transcripts move to a private JSON file?
5. Is `indianopinion/llm-json-php` acceptable as the one runtime dependency
   for this repo, or should the draft step use the WordPress HTTP API
   directly behind a small model adapter?
6. What block size and notes shape work best for the two-pass draft on
   long recordings (case 62)?
7. Should HTML episode pages be supported by reading only a declared
   `<link rel="alternate" type="application/rss+xml">` or an `og:audio`
   tag, or stay refused (current rule)?
8. Is one option per day enough for the usage ledger, or should usage go
   in a small table for reporting across months?
9. Which default tokens-per-word figures should ship per script, given
   that the repo must not ship prices but may ship ratios, and how should
   the operator measure their own?
10. Should the transcript screen allow audio playback synced to segments
    (needs the audio kept), given that the default deletes the audio copy?

## 14. Lessons from production

There is no production history for this project; it is new. These rules
come from general experience with paid APIs, long-running jobs and
generated text, and they bind the build.

1. **Estimate before you spend, and make the human say yes.** No paid call
   happens before an explicit confirmation of a stored estimate, and a
   changed estimate needs a new confirmation.
2. **Check the switch and the cap before every call, with a fresh read.**
   A cached "enabled" or a cap checked once at the start of a long run is
   how a kill switch fails to stop spending.
3. **Store each paid result before moving on.** A chunk's result is
   written before the run advances, so a crash never pays for the same
   chunk twice except in the one documented window.
4. **Never let the model write timestamps or attributions.** Times come
   from segments; speakers come from segments; the model only cites
   segment ids. Anything it cites that does not exist is dropped and
   reported.
5. **Check every quote against the transcript.** A quote that is not in the
   cited segment does not reach the draft.
6. **Drafts only.** Force the post status back to `draft` after every
   filter. Nothing the plugin creates is visible to readers until an editor
   publishes it.
7. **Do not trust headers about media.** Content type, content length and
   declared duration are hints. Magic bytes, counted bytes and counted
   frames decide.
8. **Fetch user URLs as if they were hostile.** Safe-request options on
   every hop, size limits enforced while streaming, no redirects to plain
   HTTP.
9. **Split on frame boundaries and overlap the cuts.** A cut in the middle
   of a word costs that word; an overlap recovers it, and the overlap is
   also the only evidence that links speakers across chunks.
10. **Speaker labels are per request.** Never assume "Speaker 1" means the
    same person in two requests. Map across the overlap and flag weak
    mappings.
11. **Do not hold long calls in a web request.** Paid work runs in the
    background with a time budget; long recordings belong to WP-CLI.
12. **Account errors stop the lane, not just the job.** An authentication
    or credit error will fail every queued call the same way, so pause
    them all once and say so once.
13. **Ship no prices.** Prices change and differ by account. The operator
    enters their own, and an estimate without prices still shows minutes
    and tokens.
14. **Normalise text before comparing it.** Unicode NFC, case folding and
    quote unification before any quote or seam match, or non-Latin text
    and typographic quotes fail to match.
15. **Delete what you copied.** Private audio copies are removed as soon as
    they are not needed; orphans are swept daily.
