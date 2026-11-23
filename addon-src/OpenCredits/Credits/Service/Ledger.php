<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Service;

/**
 * Ledger vocabulary: every trigger's display label and the marker strings
 * that prune/dedupe and wallet history recognise. One edit here reaches every
 * reader. Marker values are stored data — changing one orphans old rows.
 */
class Ledger
{
    public const TRIGGER_THREAD = 'thread';
    public const TRIGGER_POST = 'post';
    public const TRIGGER_REACTION = 'reaction_received';
    public const TRIGGER_REGISTER = 'register';
    public const TRIGGER_DAILY_LOGIN = 'daily_login';
    public const TRIGGER_TRANSFER = 'transfer';
    public const TRIGGER_REDEEM = 'redeem';
    public const TRIGGER_CHARGE = 'charge';
    public const TRIGGER_DEMURRAGE = 'demurrage';

    public const TRIGGER_LABELS = [
        self::TRIGGER_THREAD => 'New thread',
        self::TRIGGER_POST => 'Reply',
        self::TRIGGER_REACTION => 'Reaction received',
        self::TRIGGER_REGISTER => 'Registration',
        self::TRIGGER_DAILY_LOGIN => 'Daily visit',
        self::TRIGGER_REDEEM => 'Redeem code',
        self::TRIGGER_CHARGE => 'Premium content',
        self::TRIGGER_DEMURRAGE => 'Demurrage',
    ];

    /** Note on a historical-backfill lump sum (completion and prune guards). */
    public const NOTE_BACKFILL = 'Historical backfill';

    /** Dedupe-key prefix for reaction rows; prune keeps these rows forever. */
    public const NOTE_REACTION_PREFIX = 'reaction:';

    /** Ledger-only triggers: rows exist, but members never earn from them. */
    public const LEDGER_ONLY_TRIGGERS = [
        self::TRIGGER_TRANSFER,
        self::TRIGGER_REDEEM,
        self::TRIGGER_CHARGE,
        self::TRIGGER_DEMURRAGE,
    ];

    public static function label(string $trigger): string
    {
        return self::TRIGGER_LABELS[$trigger] ?? ucwords(str_replace('_', ' ', $trigger));
    }

    /**
     * Triggers an admin may configure as an event. Ledger-only triggers
     * (transfer, redeem, charge, demurrage) must never appear here.
     *
     * @return array<string, string>
     */
    public static function eventTriggers(): array
    {
        return array_diff_key(self::TRIGGER_LABELS, array_flip(self::LEDGER_ONLY_TRIGGERS));
    }

    public static function isBackfillNote(string $note): bool
    {
        return $note === self::NOTE_BACKFILL;
    }

    /**
     * Bind values for the prune/dedupe guard
     * (`trigger != ? AND note != ? AND note NOT LIKE ?`): redeem rows and
     * backfill/reaction markers are completion guards and dedupe state.
     *
     * @return array<int, string>
     */
    public static function pruneGuardParams(): array
    {
        return [self::TRIGGER_REDEEM, self::NOTE_BACKFILL, self::NOTE_REACTION_PREFIX . '%'];
    }
}
