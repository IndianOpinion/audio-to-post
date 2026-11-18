<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost;

/**
 * Run states and the allowed transitions (section 4.1).
 */
final class RunState
{
    public const NEW = 'new';
    public const FETCHING = 'fetching';
    public const PROBED = 'probed';
    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const QUEUED = 'queued';
    public const TRANSCRIBING = 'transcribing';
    public const PAUSED = 'paused';
    public const TRANSCRIPT_READY = 'transcript_ready';
    public const DRAFTING = 'drafting';
    public const DRAFT_READY = 'draft_ready';
    public const EMPTY = 'empty';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public const ALL = [
        self::NEW, self::FETCHING, self::PROBED, self::AWAITING_CONFIRMATION, self::QUEUED, self::TRANSCRIBING,
        self::PAUSED, self::TRANSCRIPT_READY, self::DRAFTING, self::DRAFT_READY, self::EMPTY, self::FAILED,
        self::CANCELLED, self::EXPIRED,
    ];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::NEW => [self::FETCHING, self::FAILED, self::CANCELLED],
        self::FETCHING => [self::PROBED, self::FAILED, self::CANCELLED],
        self::PROBED => [self::AWAITING_CONFIRMATION, self::QUEUED, self::FAILED, self::CANCELLED],
        self::AWAITING_CONFIRMATION => [self::QUEUED, self::CANCELLED, self::EXPIRED],
        self::QUEUED => [self::TRANSCRIBING, self::PAUSED, self::CANCELLED],
        self::TRANSCRIBING => [self::TRANSCRIPT_READY, self::EMPTY, self::PAUSED, self::FAILED, self::CANCELLED],
        self::PAUSED => [self::QUEUED, self::CANCELLED],
        self::TRANSCRIPT_READY => [self::DRAFTING],
        self::DRAFTING => [self::DRAFT_READY, self::TRANSCRIPT_READY, self::PAUSED],
        self::DRAFT_READY => [self::DRAFTING],
        self::FAILED => [self::QUEUED],
        self::EXPIRED => [self::PROBED],
        self::EMPTY => [],
        self::CANCELLED => [],
    ];

    /** States in which retention clocks for audio start (case 80). */
    public const AUDIO_DONE = [self::TRANSCRIPT_READY, self::EMPTY, self::CANCELLED, self::EXPIRED, self::DRAFTING, self::DRAFT_READY];

    /** States a run may be deleted from by retention (case 81). */
    public const FINAL = [self::TRANSCRIPT_READY, self::DRAFT_READY, self::EMPTY, self::FAILED, self::CANCELLED, self::EXPIRED];

    /** States the runner picks up (case 58). */
    public const ACTIVE = [self::NEW, self::FETCHING, self::QUEUED, self::TRANSCRIBING, self::PAUSED, self::DRAFTING, self::AWAITING_CONFIRMATION, self::TRANSCRIPT_READY, self::DRAFT_READY];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Transitions are listed in the PRD; the extra `probed` to `failed` and
     * `probed` to `cancelled` edges cover the probe-time failures of cases
     * 11, 15 and 25 and a cancel before the estimate is shown.
     *
     * @throws StateException
     */
    public static function assertMove(string $from, string $to): void
    {
        if (!self::canMove($from, $to)) {
            throw new StateException('invalid_transition', sprintf('A run cannot move from %s to %s.', $from, $to));
        }
    }
}
