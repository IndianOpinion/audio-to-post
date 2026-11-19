=== Audio to post ===
Contributors: indianopinion
Tags: transcription, podcast, audio, speech-to-text, drafts
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Turns an audio file or a podcast episode into a transcript and a draft post, with speaker turns, timestamps and a cost estimate you confirm first.

== Description ==

Written from scratch; not derived from any third-party plugin.

* Sources: Media Library audio, a direct audio URL, a podcast RSS episode, a published Podcasting 2.0 transcript, or a WebVTT, SRT or JSON transcript file.
* Measures the recording for free and shows billed minutes, chunks and draft tokens before any paid call. Prices appear only when you enter your own.
* Splits long MP3 and WAV files on frame boundaries with a small overlap, and stitches the pieces with consistent speakers and true timestamps.
* Drafts in three modes: article, show notes and cleaned transcript. Quotes are checked against the transcript. Everything is a draft.
* Daily caps and a kill switch of its own. Works with gen-queue and spend-meter when they are active.
* Exports JSON, WebVTT, SRT and plain text.

Audio is sent only to the transcription service you configure, and transcript text only to the model you configure.

== Installation ==

1. Upload the plugin folder and activate it.
2. Go to Audio to post > Settings, choose the adapter and enter its endpoint, model and key.
3. Create a run under Audio to post > Runs.

For recordings over 30 minutes, run `wp audio-to-post run` from a system cron.

== Frequently Asked Questions ==

= Does it publish posts? =

No. It only creates drafts.

= When is audio deleted? =

Only under the retention settings on the settings screen, and every deletion is logged.

== Changelog ==

= 0.1.0 =
* First release.
