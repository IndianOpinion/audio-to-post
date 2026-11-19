# audio-to-post

A WordPress plugin that turns an uploaded audio file or a podcast episode
into a transcript and then a draft post, keeping speaker turns and
timestamps. It splits long recordings, shows the editor the minutes and
tokens a run will use before any paid call, and never publishes anything.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party
plugin it integrates with.

Measured with the bundled fake adapter on a generated 61-minute MP3
(`php examples/estimate-and-stitch.php 61`): 7 chunks, 62.1 billed minutes
estimated with overlaps, 9150 of 9150 words back after stitching with none
duplicated, 4.9 s wall time on one core. No real provider run is recorded
yet.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Hooks, functions, REST and WP-CLI](#hooks-functions-rest-and-wp-cli)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Needs PHP 8.1 or later with mbstring (intl recommended), and WordPress 6.4
or later.

With Composer (this also installs `indianopinion/llm-json-php`, the model
caller for drafts):

```sh
cd wp-content/plugins
git clone https://github.com/IndianOpinion/audio-to-post.git
cd audio-to-post && composer install --no-dev
```

Without Composer: copy the plugin folder into `wp-content/plugins/` and
activate it. Transcription, the `transcript` draft mode and every export
work. The `article` and `show_notes` modes need a model caller: either put
a copy of [llm-json-php](https://github.com/IndianOpinion/llm-json-php) in
`vendor/` with Composer, or supply your own caller with the
`audiopost_llm_caller` filter (see `examples/site-caller.php`).

As a must-use plugin: put the folder at
`wp-content/mu-plugins/audio-to-post/` and copy
`mu-loader/audio-to-post-loader.php` to `wp-content/mu-plugins/`.

**nginx users:** the private audio directory has a deny-all `.htaccess`,
which nginx ignores. Add:

```nginx
location ^~ /wp-content/uploads/audio-to-post/ { deny all; }
```

The Status screen warns when a test file in that directory is reachable.

## Quick start

1. **Settings > Audio to post.** Choose the transcription adapter
   (`http-multipart`), set the endpoint URL, model name and key, and check
   the size and length limits against your provider's documentation.
2. Optionally set the draft model and its key, and your own prices.
3. **Audio to post > Runs > New run.** Upload a file, give an attachment
   ID, paste an audio URL, or paste a podcast feed URL and pick an episode.
   Tick the rights box.
4. The run is measured in the background. Open it, read the estimate, and
   press **Start**. Nothing is paid for before that.
5. When the transcript is ready, name the speakers on the transcript
   screen, then open the draft post and edit it. Review notes are in a box
   on the edit screen.

From the shell:

```sh
wp audio-to-post add episode.mp3 --rights-confirmed --mode=show_notes
wp audio-to-post confirm 42
wp audio-to-post run --time=1800
wp audio-to-post export 42 --format=vtt > episode.vtt
```

Keys can live in `wp-config.php` instead of the database:

```php
define('AUDIOPOST_ADAPTER_KEY_HTTP_MULTIPART', '...');
define('AUDIOPOST_MODEL_KEY', '...');
```

## Configuration

| setting | default | notes |
|---|---|---|
| Transcription adapter | none | must be chosen before a paid run |
| Endpoint, model, key, limits, response paths | empty, 25 MB, 1 200 s | `http-multipart` adapter |
| Draft model provider, model, base URL | openrouter, empty | through llm-json-php |
| Prices per audio minute and per million tokens, currency | empty | no prices ship; without them the estimate shows minutes and tokens |
| Default draft mode | `article` | `article`, `show_notes`, `transcript`, `none` |
| Append transcript to draft | off | adds a Details block |
| Language | `auto` | or a BCP 47 tag |
| Chunk seconds, overlap seconds | 600, 10 | overlap 0 to 30 |
| Max audio size, max duration | 500 MB, 4 h | |
| Words per minute, characters per minute | 150, 250 | estimate only |
| Tokens per word | 1.3 Latin, 2.5 Indic and Arabic, 1.0 per character CJK | estimates; per-language overrides |
| Max output tokens | 2 000 article, 1 500 show notes, 600 per notes pass | |
| Single-pass limit, block size | 60 000 tokens, 1 500 words | longer transcripts use two passes |
| Daily caps | 120 audio minutes, 200 000 tokens, no money cap | empty removes a cap, zero blocks |
| Kill switch | off | `AUDIOPOST_DISABLED` forces it on |
| Attempts, HTTP timeout | 4, 300 s | |
| Async timeout, parallel runs, estimate expiry | 6 h, 1, 24 h | |
| Keep audio after transcript, after failure | 0 h, 168 h | the only way audio is deleted |
| Remove audio copies on uninstall | off | |
| Run retention | 90 days | 0 keeps for ever |

Constants: `AUDIOPOST_DISABLED`, `AUDIOPOST_ADAPTER_KEY_<ID>`,
`AUDIOPOST_MODEL_KEY`, `AUDIOPOST_DAILY_MINUTES`, `AUDIOPOST_DAILY_TOKENS`,
`AUDIOPOST_DAILY_MONEY` (may only lower a cap) and
`AUDIOPOST_DISABLE_CRON_RUNNER` (WP-Cron then does housekeeping only).

## How it works

1. **Fetch and measure (free).** The file is copied or streamed to
   `wp-content/uploads/audio-to-post/` under a random name. Magic bytes
   decide the format; MP3 frames are counted for an exact length.
   Editor-supplied URLs are checked on every redirect hop: no private
   addresses, at most five redirects, no https to http.
2. **Plan.** Files within the provider's limits go whole. Longer MP3 and
   WAV files are split on frame boundaries into chunks of about ten
   minutes with a 10 s overlap; the last chunk is never under 30 s.
3. **Estimate and confirm.** Billed minutes include the overlaps and the
   provider's rounding; draft tokens follow the formula in the PRD. A price
   shows only when every needed price is set. The editor's confirmation
   carries a fingerprint; if anything that affects cost changed, the
   confirmation is refused.
4. **Transcribe.** In the background (WP-Cron every minute, WP-CLI, or
   gen-queue when present). Before every call: kill switch, daily caps,
   `audiopost_can_spend`, spend-meter, cancel flag. Each result is stored
   before the run moves on.
5. **Stitch.** Speaker labels restart in every request, so they are mapped
   across each overlap by shared speaking time; weak mappings are flagged.
   Times are shifted to the recording. The seam is cut in a gap between
   words near the middle of the overlap.
6. **Draft.** The model sees segment ids, never times. Quotes must appear
   in the cited segment; anything else is removed and listed. Speakers and
   times come from segments. The post is always a `draft`.
7. **Clean up.** Audio copies are deleted only by the retention settings,
   and every deletion is logged and fires an action.

Design notes and every decision are in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md); the requirements are in
[docs/PRD.md](docs/PRD.md).

**WP-Cron and long recordings.** A loopback WP-Cron request may be cut off
by the web server before a long call ends. For recordings over 30 minutes,
run `wp audio-to-post run` from a system cron and set
`AUDIOPOST_DISABLE_CRON_RUNNER`.

## Hooks, functions, REST and WP-CLI

Functions: `audiopost_create_run`, `audiopost_get_run`,
`audiopost_confirm_run`, `audiopost_cancel_run`, `audiopost_retry_run`,
`audiopost_create_draft`, `audiopost_get_transcript`,
`audiopost_export_transcript`, `audiopost_is_disabled`,
`audiopost_usage_today`, `audiopost_register_adapter`.

Actions: `audiopost_register_adapters`, `audiopost_run_created`,
`audiopost_run_state_changed`, `audiopost_estimate_ready`,
`audiopost_chunk_done`, `audiopost_transcript_ready`,
`audiopost_draft_created`, `audiopost_run_failed`,
`audiopost_usage_recorded`, `audiopost_cap_reached`,
`audiopost_kill_switch_changed`, `audiopost_recording_deleted`.

Filters: `audiopost_prompt`, `audiopost_draft_schema`,
`audiopost_draft_post` (status is forced back to `draft` afterwards),
`audiopost_draft_blocks`, `audiopost_max_bytes`, `audiopost_max_seconds`,
`audiopost_chunk_plan`, `audiopost_overlap_seconds`, `audiopost_can_spend`,
`audiopost_capability_create`, `audiopost_capability_manage`,
`audiopost_tick_seconds`, `audiopost_estimate_ttl`,
`audiopost_retention_days`, `audiopost_llm_caller`,
`audiopost_allowed_hosts`. An invalid filter result is ignored and logged.

REST (`audio-to-post/v1`): `POST /runs`, `GET /runs`, `GET /runs/{id}`,
`POST /runs/{id}/confirm`, `POST /runs/{id}/cancel`,
`POST /runs/{id}/retry`, `POST /runs/{id}/drafts`,
`GET|PATCH /runs/{id}/transcript`, `GET /feed-episodes?url=`.

WP-CLI: `wp audio-to-post add|estimate|confirm|run|status|list|show|cancel|retry|draft|export|disable|enable|purge`.
Exit codes: 0 success, 1 error, 3 stopped by the kill switch, 4 stopped by
a daily cap.

Adapters: implement `IndianOpinion\AudioToPost\Adapter\TranscriptionAdapter`
and register it on `audiopost_register_adapters`
(`examples/custom-adapter.php`).

## Limitations

- Only MP3 and WAV are split. A long Ogg, FLAC, M4A or WebM file must be
  within the provider's limits, or be converted.
- No video, no YouTube or other video sites, no HTML episode pages.
- No audio decoding: chunk boundaries are frame boundaries, and the
  provider decides what is speech.
- One bundled transcription adapter, for the common synchronous
  multipart request shape. Submit-and-poll services need an adapter of
  your own; the runner supports them.
- No synced playback on the transcript screen.
- Prices are never shipped and the estimate is not a quote.
- Speaker labels are anonymous; the plugin never identifies a voice.

## FAQ

**Does it publish anything?** No. Every post it creates is a draft, even
if a filter asks for something else.

**Where are the recordings?** In `wp-content/uploads/audio-to-post/`,
with random names, until the retention setting deletes them. The Status
screen lists every file and its due time.

**Why did my run pause?** Look at the run's state reason: `kill_switch`,
`daily_cap` (resumes after local midnight), `spend_meter`, or
`provider_hold` (another run hit an authentication or credit error).

**The estimate was off.** Each run screen shows estimate against actual.
Tune words per minute and the tokens-per-word ratio for your languages.

**Can I bring my own model client?** Yes: return a callable from
`audiopost_llm_caller`. The plugin still checks the schema, the quotes,
the caps and the kill switch.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.
