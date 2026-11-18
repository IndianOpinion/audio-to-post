# Contributing

Thank you for helping. Issues and pull requests are welcome.

## Setup

```sh
git clone https://github.com/IndianOpinion/audio-to-post.git
cd audio-to-post
composer install
```

PHP 8.1 or later with mbstring. intl is recommended.

## Tests and checks

```sh
composer test      # PHPUnit: unit tests and WordPress-stub integration tests
composer analyse   # PHPStan, level 8
composer lint      # PHP_CodeSniffer, PSR-12
composer check     # all three
```

Tests run without WordPress (a stub layer lives in `tests/stubs/`) and
without network. Audio fixtures are generated in the test; do not add
recorded audio. `AUDIOPOST_UPDATE_GOLDEN=1 composer test` rewrites the
export golden files; review the diff before committing.

## Style

- `declare(strict_types=1);` in every PHP file, PSR-12, PHP 8.1 syntax only.
- Typed exceptions with a machine code; no `@`, no `eval`, no shell.
- Escape all output in WordPress screens; nonce and capability checks on
  every form and route.
- Short sentences, plain words and British spelling in docs.

## Pull requests

- One change per pull request, with tests for new behaviour.
- Name the PRD case a change affects, and update `docs/ARCHITECTURE.md`
  when you change a decision.
- CI must be green. A DCO-style sign-off is not required.
