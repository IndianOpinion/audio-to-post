# Changelog

All notable changes to this project are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- Runs from a Media Library attachment, a direct audio URL, a podcast RSS
  episode, a published Podcasting 2.0 transcript, or an uploaded WebVTT,
  SRT or JSON transcript, with a rights confirmation for audio.
- Free probe: magic-byte format detection, exact MP3 length by frame
  count, WAV length from the data chunk, video refusal.
- Chunk planner and pure-PHP MP3 and WAV splitters with a 10 s overlap and
  no short tail.
- Estimate of billed minutes and draft tokens with fingerprinted
  confirmation; prices only from the operator's own figures.
- Background runner with fresh-read kill switch, daily caps, a spend
  filter, spend-meter and cancel checks before every call; retries,
  `Retry-After`, 413 splitting, provider hold, async job polling.
- Stitcher with speaker mapping across overlaps, global timestamps and
  seam deduplication.
- Draft step in `article`, `show_notes` and `transcript` modes through
  llm-json-php or a site caller, with schema and quote checks, review
  notes and drafts only.
- Transcript editing with revisions; JSON, WebVTT, SRT and text export.
- Retention of audio copies by operator settings only, logged deletions,
  orphan handling, run retention, privacy exporter and eraser.
- REST API, WP-CLI commands, admin screens, uninstall routine and a
  must-use loader.
- spend-meter (contract 1) and gen-queue (0.1.0) integration through
  their documented hooks only.
- In-flight holds against the daily caps, a buffer for usage rows the
  table refuses, DNS pinning of every fetch hop, and a private directory
  that fails closed.
